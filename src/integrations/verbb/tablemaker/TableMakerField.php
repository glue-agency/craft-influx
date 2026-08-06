<?php

namespace GlueAgency\Influx\integrations\verbb\tablemaker;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\TableCells;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for Verbb's Table Maker field.
 *
 * Structurally this is {@see \GlueAgency\Influx\fields\Table} with one thing
 * moved, and that one thing decides the whole design: a Craft Table's columns are
 * FIELD SETTINGS, so the mapping card can offer a row per column and be sure the
 * columns exist. A Table Maker field's columns are CONTENT — the field itself
 * carries nothing but two "should the editor see this sub-column" toggles
 * (`enableWidthColumn`, `enableAlignmentColumn`), and every entry authors its own
 * headings. So there is nothing to derive a card from, and a sync that only wrote
 * rows would write them into a table with no columns to hang them on.
 *
 * The operator therefore declares the columns as part of the MAPPING, and they're
 * written verbatim on every sync alongside the rows. From there it is the Table
 * strategy again: one sub-mapping per declared column, node paths ABSOLUTE
 * (resolved against the top-level item), and rows built by index-zipping the
 * per-column value lists — `consultations.day` collapses to every consultation's
 * day, and row N takes the Nth value of every column.
 *
 *   mappings[<handle>] = {
 *       options: { columns: [
 *           { id: 'c1', heading: 'Day',  type: 'singleline' },
 *           { id: 'c2', heading: 'From', type: 'time', width: '100' },
 *       ] },
 *       fields: {
 *           c1: { node: 'consultations.day' },
 *           c2: { node: 'consultations.from' },
 *       },
 *   }
 *
 * The column `id` is Influx's, not Table Maker's — Table Maker stores columns as a
 * positional list with no identity at all, so a mapping keyed on position would
 * silently re-point every cell the moment a column was inserted or reordered. The
 * id is minted when a column is added and never reused; it is stripped on the way
 * out, since the stored value has no place for it.
 *
 * `select` columns are deliberately not offered. A dropdown column carries its own
 * option list, which the operator has no way to declare here, and writing an
 * arbitrary feed string into a cell whose value must be one of a closed set would
 * store something the CP can't render back. Every other Craft column type needs no
 * configuration beyond its name.
 *
 * Sync semantics are full-replace, like Table and Matrix: {@see parse()} rebuilds
 * the whole table, so a declared column the feed doesn't address is written empty.
 * {@see valueDiffers()} compares columns and rows together — a heading edited in
 * the mapping IS a change to the field, even when every cell is identical — with
 * the cells reduced by their column type ({@see TableCells::cellPrint()}) because a
 * CP round-trip stores a checkbox as a real bool and a date as a DateTime while the
 * feed carries "yes" and an ISO string.
 *
 * A Table Maker row reports as one value in the log rather than drilling down per
 * row, as an {@see \GlueAgency\Influx\fields\Addresses} mapping does — the
 * per-child drill-down {@see \GlueAgency\Influx\fields\Table} has is a separate
 * piece of work.
 *
 * Keyed by class string, so an install without the plugin never touches it.
 */
class TableMakerField extends Field
{
    use TableCells;

    /**
     * The Craft column types a declared column may take, `value => label`.
     *
     * Table Maker's own list minus `select` (see the class docblock) and minus
     * `heading`, which Craft offers for the row-header column pattern a
     * feed-written table has no use for. Labels are Craft's own strings, the
     * same ones the field's editor shows.
     *
     * @return array<string, string>
     */
    public static function columnTypes(): array
    {
        return [
            'singleline'  => Craft::t('app', 'Single-line text'),
            'multiline'   => Craft::t('app', 'Multi-line text'),
            'number'      => Craft::t('app', 'Number'),
            'checkbox'    => Craft::t('app', 'Checkbox'),
            'lightswitch' => Craft::t('app', 'Lightswitch'),
            'color'       => Craft::t('app', 'Color'),
            'date'        => Craft::t('app', 'Date'),
            'time'        => Craft::t('app', 'Time'),
            'url'         => Craft::t('app', 'URL'),
            'email'       => Craft::t('app', 'Email'),
        ];
    }

    public static function craftFieldClass(): ?string
    {
        return 'verbb\tablemaker\fields\TableMakerField';
    }

    /**
     * Neither cell of its own — the value comes entirely from the columns and
     * their sub-mappings, the same declaration {@see \GlueAgency\Influx\fields\Table}
     * makes — and one card that is both the column editor and the mapping rows.
     *
     * Those two can't be separate nodes: the rows ARE the declared columns, so
     * adding a column has to add its row in the same breath, off state neither the
     * server nor a second card can see until it's saved.
     *
     * The field's two editor toggles ride along, so the mapping offers exactly the
     * sub-columns the entry editor does — a site that hides widths doesn't get to
     * set them here either. Read defensively: the class may not be installed, and
     * an unknown property on a Yii component throws rather than coalescing.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => false,
            'default' => false,
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->node('tableMakerColumns', [
                'handle'      => 'columns',
                'label'       => Craft::t('influx', 'Columns'),
                'columnTypes' => static::columnTypes(),
                'enableWidth' => static::setting($field, 'enableWidthColumn'),
                'enableAlign' => static::setting($field, 'enableAlignmentColumn'),
                'addLabel'    => Craft::t('influx', 'Add a column'),
                'emptyHint'   => Craft::t('influx', 'Add a column to start mapping this table.'),
            ]),
        ]);
    }

    /**
     * Addressed when any active column mapping is addressed for this item — the
     * node-less rule {@see \GlueAgency\Influx\fields\Table::addressed()} uses.
     * Declared columns alone are not enough: a mapping the feed never reaches
     * must leave the field alone rather than blanking it to bare headings.
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
     * The whole field value: the declared columns, plus rows index-zipped from
     * the mapped columns' value lists.
     *
     * Both halves are positional and share one order — Table Maker reads a cell
     * as `$row[$i]` against `$columns[$i]`, so a row is a fixed-width list with a
     * null wherever a column's list doesn't reach that far, never a sparse map.
     *
     * The columns are written even when no row survives: `addressed()` was true,
     * so the feed is authoritative, and a table of headings with no rows is what
     * "the feed carries none of these" looks like.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<list<mixed>>}
     */
    public function parse(FieldContext $context): mixed
    {
        $columns = $this->declaredColumns($context->mapping);
        $subs = $this->activeColumnMappings($context->mapping);
        $lists = [];

        foreach ($columns as $column) {
            $sub = $subs[$column['id']] ?? null;
            $resolved = $sub?->resolve($context->item);
            $lists[] = $resolved === null ? [] : $this->valueList($resolved);
        }

        $rowCount = $this->maxLength($lists);
        $rows = [];

        for ($i = 0; $i < $rowCount; $i++) {
            $row = [];

            foreach ($columns as $index => $column) {
                $row[] = array_key_exists($i, $lists[$index])
                    ? $this->coerceCell((string) ($column['type'] ?? ''), $lists[$index][$i])
                    : null;
            }

            $rows[] = $row;
        }

        return ['columns' => $this->storableColumns($columns), 'rows' => $rows];
    }

    /**
     * Compare the columns and the rows, and nothing else.
     *
     * The stored value carries a third key Craft never asked for: `table`, the
     * rendered HTML preview Table Maker builds in `normalizeValue()` from the
     * other two. It is derived, it is a Markup object, and it is regenerated on
     * every read — comparing it would report a change on every single sync.
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $columns = $this->declaredColumns($context->mapping);

        return $this->fingerprint($current, $columns) !== $this->fingerprint($incoming, $columns);
    }

    /**
     * One side's comparable form: the columns as their storable selves, and the
     * rows as per-column reduced cells.
     *
     * Both sides walk the DECLARED columns rather than their own, so the two
     * always have the same width and the same order even when the stored side
     * predates a column the mapping has since added. A non-array value (a cleared
     * field's null) fingerprints as no columns and no rows, so it differs from
     * anything the feed produces and matches an incoming clear.
     *
     * @param list<array<string, mixed>> $columns declared columns
     * @return array{columns: list<array<string, mixed>>, rows: list<list<mixed>>}
     */
    protected function fingerprint(mixed $value, array $columns): array
    {
        if (! is_array($value)) {
            return ['columns' => [], 'rows' => []];
        }

        $storedColumns = is_array($value['columns'] ?? null) ? array_values($value['columns']) : [];
        $print = ['columns' => [], 'rows' => []];

        foreach (array_values($columns) as $index => $column) {
            $print['columns'][] = $this->columnPrint($storedColumns[$index] ?? $column);
        }

        foreach (is_array($value['rows'] ?? null) ? $value['rows'] : [] as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            $reduced = [];

            foreach (array_values($columns) as $index => $column) {
                $reduced[] = $this->cellPrint((string) ($column['type'] ?? ''), $cells[$index] ?? null);
            }

            $print['rows'][] = $reduced;
        }

        return $print;
    }

    /**
     * One column's comparable form. Scalars only, so two prints compare with
     * `===`, and only the keys Table Maker stores — a stored column may carry a
     * decoded `options` array a declared one never has.
     *
     * @param array<string, mixed> $column
     * @return array<string, string>
     */
    protected function columnPrint(array $column): array
    {
        return [
            'heading' => trim((string) ($column['heading'] ?? '')),
            'type'    => (string) ($column['type'] ?? 'singleline'),
            'align'   => (string) ($column['align'] ?? ''),
            'width'   => (string) ($column['width'] ?? ''),
        ];
    }

    /**
     * The declared columns, as a clean list of `{id, heading, type, align, width}`.
     *
     * Anything without an id is dropped: the id is what ties a column to its
     * sub-mapping, and a column that lost it can't be mapped to anything. Stored
     * config is operator-authored JSON, so nothing here trusts its shape.
     *
     * @return list<array<string, mixed>>
     */
    protected function declaredColumns(FieldMapping $mapping): array
    {
        $columns = [];

        foreach ((array) $mapping->option('columns', []) as $column) {
            if (! is_array($column) || ($column['id'] ?? '') === '') {
                continue;
            }

            $columns[] = [
                'id'      => (string) $column['id'],
                'heading' => (string) ($column['heading'] ?? ''),
                'type'    => isset(static::columnTypes()[$column['type'] ?? '']) ? (string) $column['type'] : 'singleline',
                'align'   => (string) ($column['align'] ?? ''),
                'width'   => (string) ($column['width'] ?? ''),
            ];
        }

        return $columns;
    }

    /**
     * The declared columns as Table Maker stores them — Influx's `id` stripped,
     * since the stored shape is a positional list with no identity of its own.
     *
     * @param list<array<string, mixed>> $columns
     * @return list<array<string, mixed>>
     */
    protected function storableColumns(array $columns): array
    {
        return array_map(
            static fn(array $column): array => [
                'heading' => (string) $column['heading'],
                'width'   => (string) $column['width'],
                'align'   => (string) $column['align'],
                'type'    => (string) $column['type'],
            ],
            $columns,
        );
    }

    /**
     * This mapping's active per-column sub-mappings, keyed by column id.
     *
     * @return array<string, FieldMapping>
     */
    protected function activeColumnMappings(FieldMapping $mapping): array
    {
        $subs = [];

        foreach ($mapping->subMappings() as $sub) {
            if ($sub->isActive()) {
                $subs[$sub->handle] = $sub;
            }
        }

        return $subs;
    }

    /**
     * A boolean field setting, defaulting to true when the property isn't there —
     * the plugin may be absent, an older version may not declare it, and a Yii
     * component throws on an unknown property rather than returning null.
     */
    protected static function setting(CraftFieldInterface $field, string $property): bool
    {
        return property_exists($field, $property) ? (bool) $field->$property : true;
    }
}
