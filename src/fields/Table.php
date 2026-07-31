<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Table as CraftTableField;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for Craft's Table field. The mapping carries one
 * sub-mapping per COLUMN under the flat `fields` channel
 * ({@see FieldMapping::subMappings()}), whose node paths are ABSOLUTE
 * (resolved against the top-level item, exactly like Matrix's block children):
 *
 *   mappings[<tableHandle>] = {
 *       fields: {
 *           col1: { node: 'specs.label' },
 *           col2: { node: 'specs.value' },
 *       },
 *   }
 *
 * Rows are built by index-zipping the per-column value lists
 * ({@see Field::valueList()} / {@see Field::maxLength()}) — the same rule
 * {@see Matrix} zips its blocks with: `specs.label` collapses to the list of
 * every spec's label, and row N takes the Nth value of every mapped column.
 * The parent row itself has NO node: its value comes entirely from the
 * sub-mappings ({@see fieldMeta()}, {@see addressed()}).
 *
 * Mappings are keyed by COLUMN ID (`col1`), never by the column's handle:
 * `serializeValue()` / `serializeValueForDb()` read a cell as `$row[$colId]`
 * only — no handle coalesce — and a column's handle is both optional and
 * renameable, while its colId is neither. Keying on the id makes a mapping
 * survive a handle rename; the builder still LABELS each row by the column's
 * heading ({@see columnLabel()}).
 *
 * Sync semantics are full-replace: {@see parse()} rebuilds the whole row set
 * from the feed, so a column the mapping doesn't address is written empty.
 * {@see valueDiffers()} compares a mapped-columns-only fingerprint, so an
 * unchanged feed never triggers that rebuild — and each cell is reduced by its
 * COLUMN TYPE ({@see cellPrint()}) before comparing, because a CP round-trip
 * stores a checkbox as a real bool and a date as a DateTime while the feed
 * carries "yes" and an ISO string.
 *
 * Known v1 limitation — array-valued nodes mis-fan, exactly as {@see Matrix}
 * documents: a node resolving to a flat array meant as ONE row's value is
 * indistinguishable from per-row scalar values, so it spreads across rows.
 *
 * Caveat on `staticRows` tables: Craft clamps and reorders the value to the
 * configured row set on normalize, so a feed writing a different row count
 * reads as changed on every sync (the comparison sees the clamped stored rows,
 * never the ones the feed asked for). Map a static table column-for-column with
 * a feed that ships exactly as many rows, or leave it unmapped.
 */
class Table extends Field
{
    /**
     * Column types whose value is a flag rather than text, coerced through
     * {@see Lightswitch::coerce()} on both the write and the comparison.
     * Craft's own `_normalizeCellValue()` has no case for either, so an
     * uncoerced "yes" would land in the cell verbatim.
     */
    protected const BOOLEAN_TYPES = ['checkbox', 'lightswitch'];

    /** Column types Craft trims and stores as plain text. */
    protected const TEXT_TYPES = ['singleline', 'multiline'];

    public static function craftFieldClass(): ?string
    {
        return CraftTableField::class;
    }

    /**
     * ONE always-visible card holding a row per mappable column, writing the
     * mapping's flat `fields` channel. A `select` column offers its own options
     * as the row's default-value editor — the stored option values are a closed
     * set the operator shouldn't have to retype — led by a blank choice so a
     * picked default stays clearable (the same leading `—` the top-level
     * default select carries).
     */
    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        $columns = $this->mappableColumns($field);

        if (! $columns) {
            return SchemaBuilder::make()
                ->note(['text' => Craft::t('influx', 'This Table field has no mappable columns yet.')]);
        }

        $subFields = SchemaBuilder::make();

        foreach ($columns as $colId => $column) {
            $config = [
                'handle' => $colId,
                'label'  => $this->columnLabel($colId, $column),
            ];

            if (($column['type'] ?? null) === 'select') {
                $subFields->select($config + ['options' => $this->columnOptions($column)]);

                continue;
            }

            $subFields->text($config);
        }

        return SchemaBuilder::make()->subFields([
            'label'        => Craft::t('influx', 'Columns'),
            'instructions' => Craft::t('influx', 'Each column reads its own source node; the values are zipped by index into rows. Mappings are keyed by column ID, so renaming a column’s handle keeps them intact.'),
            'subFields'    => $subFields->toArray(),
        ]);
    }

    /**
     * The Table row's value derives entirely from its per-column sub-mappings —
     * there is no source node or default on the row itself, the same flag
     * {@see Matrix} declares.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [
            'subfieldsOnly' => true,
        ];
    }

    /**
     * A node-less Table row is addressed via its per-column sub-mappings, never
     * its own (absent) node — so it's addressed when ANY active column mapping
     * is addressed for this item. A row whose columns are all inactive or
     * entirely unaddressed leaves the field untouched.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeColumnMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the table value by index-zipping the mapped columns' value lists.
     *
     * Every mapped column gets a key on EVERY row, null where its list doesn't
     * reach that far: the rows are a fixed-width record because Craft's
     * serializers read a cell as `$row[$colId]` un-coalesced, and an explicit
     * null is what an empty cell holds anyway. A column mapped to a node that
     * resolves to nothing keeps its (all-null) keys for the same reason.
     *
     * A configured colId the field no longer declares is skipped silently —
     * unlike an unknown Matrix block type, a removed column is not a structural
     * error: the mapping simply outlived the column, and the remaining columns
     * still have a table to write into.
     *
     * An empty result is returned as an explicit clear rather than null:
     * {@see addressed()} was true, so the feed is authoritative even when every
     * column resolved to nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(FieldContext $context): mixed
    {
        $columns = $this->mappableColumns($context->craftField);
        $lists = [];

        foreach ($this->activeColumnMappings($context->mapping) as $sub) {
            if (! isset($columns[$sub->handle])) {
                continue;
            }

            $resolved = $sub->resolve($context->item);
            $lists[$sub->handle] = $resolved === null ? [] : $this->valueList($resolved);
        }

        $rowCount = $this->maxLength(array_values($lists));

        if ($rowCount === 0) {
            return [];
        }

        $rows = [];

        for ($i = 0; $i < $rowCount; $i++) {
            $row = [];

            foreach ($lists as $colId => $values) {
                $row[$colId] = array_key_exists($i, $values)
                    ? $this->coerceCell((string) ($columns[$colId]['type'] ?? ''), $values[$i])
                    : null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Full-replace fingerprint comparison, restricted to the mapped columns so
     * an unchanged feed never triggers a destructive rebuild — and so a column
     * the feed doesn't address (which the replace writes empty) can't make every
     * sync look like a change on its own.
     *
     * Both sides are reduced per column type ({@see cellPrint()}): the stored
     * side is the field's normalized value, where Craft has already turned a
     * checkbox into a real bool and a date cell into a DateTime, while the
     * incoming side still carries whatever the feed spells them as ("yes", an
     * ISO string). Without that leaf normalisation every sync would rewrite an
     * unchanged field — the Matrix lightswitch precedent (CHANGELOG alpha.6).
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $columns = $this->mappedColumns($context);

        return $this->fingerprint($current, $columns) !== $this->fingerprint($incoming, $columns);
    }

    /**
     * One side's comparable form: a per-row map of the mapped columns' reduced
     * cells. Both sides walk the same `$columns` array, so the key order lines
     * up by construction. A non-array value (a cleared field's null)
     * fingerprints as no rows at all, so it differs from any incoming row set
     * and matches an incoming clear.
     *
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @return list<array<string, mixed>>
     */
    protected function fingerprint(mixed $rows, array $columns): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $print = [];

        foreach ($rows as $row) {
            $cells = is_array($row) ? $row : [];
            $reduced = [];

            foreach ($columns as $colId => $column) {
                $reduced[$colId] = $this->cellPrint((string) ($column['type'] ?? ''), $cells[$colId] ?? null);
            }

            $print[] = $reduced;
        }

        return $print;
    }

    /**
     * Reduce one cell to its comparable form, by column type. Flags coerce, date
     * and time columns compare by instant, text columns trim, and everything
     * else lands on the shared normaliser ({@see Field::normalize()}) — which
     * already handles the color column's ColorData (Stringable) and the number
     * column's numeric strings.
     */
    protected function cellPrint(string $type, mixed $value): mixed
    {
        return match (true) {
            in_array($type, self::BOOLEAN_TYPES, true) => Lightswitch::coerce($value),
            in_array($type, self::TEXT_TYPES, true) => $this->normalize($this->trimmed($value)),
            $type === 'date', $type === 'time' => $this->instant($value),
            default => $this->normalize($value),
        };
    }

    /**
     * A date/time cell as its instant. Parsed with both timezone flags off so a
     * timezone-less value reads as UTC on either side — which is how Craft's own
     * `normalizeValue()` reads the stored one — and so the comparison stays
     * boot-free. A value no date parse accepts keeps the base normal form rather
     * than collapsing to null, so non-dates stay distinguishable ({@see Date::normalize()}
     * takes the same stance).
     */
    protected function instant(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value) && ! $value instanceof DateTimeInterface) {
            return $this->normalize($value);
        }

        $date = DateTimeHelper::toDateTime($value, false, false);

        return $date !== false ? $date->getTimestamp() : $this->normalize($value);
    }

    /**
     * Coerce one feed value into what its column stores. Craft's
     * `_normalizeCellValue()` covers color, number, date and time but has no
     * case for the two flag types, so those are coerced here; text columns are
     * trimmed the way that same normaliser trims them, and every other type is
     * handed over raw for Craft to normalize.
     */
    protected function coerceCell(string $type, mixed $value): mixed
    {
        return match (true) {
            in_array($type, self::BOOLEAN_TYPES, true) => Lightswitch::coerce($value),
            in_array($type, self::TEXT_TYPES, true) => $this->trimmed($value),
            default => $value,
        };
    }

    /** A scalar as its trimmed string; anything else unchanged. */
    protected function trimmed(mixed $value): mixed
    {
        return is_scalar($value) ? trim((string) $value) : $value;
    }

    /**
     * The columns this mapping actually writes: the mappable columns behind its
     * active sub-mappings, in the field's declared column order. Stale colIds
     * drop out here exactly as they do in {@see parse()}.
     *
     * @return array<string, array<string, mixed>> colId → column
     */
    protected function mappedColumns(FieldContext $context): array
    {
        $mapped = [];

        foreach ($this->activeColumnMappings($context->mapping) as $sub) {
            $mapped[$sub->handle] = true;
        }

        return array_intersect_key($this->mappableColumns($context->craftField), $mapped);
    }

    /**
     * The mapping's active per-column sub-mappings ({@see FieldMapping::isActive()}) —
     * a handle with neither a node nor an explicit default contributes nothing.
     *
     * @return list<FieldMapping>
     */
    protected function activeColumnMappings(FieldMapping $mapping): array
    {
        $active = [];

        foreach ($mapping->subMappings() as $sub) {
            if ($sub->isActive()) {
                $active[] = $sub;
            }
        }

        return $active;
    }

    /**
     * The field's mappable columns, keyed by column ID in declared order.
     * `heading`-type columns are dropped: Craft's serializers skip them and
     * `normalizeValue()` overwrites their cells from the field's defaults, so a
     * mapped value could never land.
     *
     * Extracted — like {@see Matrix::blockTypeDescriptors()} — so tests can stub
     * column discovery without booting Craft.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function mappableColumns(?CraftFieldInterface $field): array
    {
        /** @var CraftTableField|null $field */
        $declared = $field->columns ?? [];

        $columns = [];

        foreach ($declared as $colId => $column) {
            if (! is_array($column) || ($column['type'] ?? null) === 'heading') {
                continue;
            }

            $columns[(string) $colId] = $column;
        }

        return $columns;
    }

    /**
     * What a column's mapping row is called: the editor-facing heading, falling
     * back to the column's handle and finally to its id — a column may carry
     * neither, and an unlabeled row would be unmappable.
     *
     * @param array<string, mixed> $column
     */
    protected function columnLabel(string $colId, array $column): string
    {
        $heading = (string) ($column['heading'] ?? '');

        if ($heading !== '') {
            return Craft::t('site', $heading);
        }

        return (string) ($column['handle'] ?? '') ?: $colId;
    }

    /**
     * A select column's options as schema select options, led by a blank choice
     * so a picked default can be cleared again. Labels are site-translated the
     * way Craft translates them on the field's own input.
     *
     * @param array<string, mixed> $column
     * @return list<array{value: string, label: string}>
     */
    protected function columnOptions(array $column): array
    {
        $options = [['value' => '', 'label' => '—']];

        foreach ($column['options'] ?? [] as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }

            $value = (string) $option['value'];
            $label = (string) ($option['label'] ?? $value);

            $options[] = [
                'value' => $value,
                'label' => $label !== '' ? Craft::t('site', $label) : $value,
            ];
        }

        return $options;
    }
}
