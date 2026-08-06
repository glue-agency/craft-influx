<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Table as CraftTableField;
use craft\helpers\DateTimeHelper;
use DateTimeInterface;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;

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
 * sub-mappings ({@see schema()}, {@see addressed()}).
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
 * Those same per-row fingerprints drive the inspectors' per-row drill-down
 * ({@see collectChildren()}) — a read-only derivation ALONGSIDE change detection,
 * never part of it. A table row is not an element, so its child carries no chip
 * and is labelled by its ordinal, with its cells named from the column headings.
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
     * set the operator shouldn't have to retype — through the same
     * {@see MappingSchemaBuilder::defaultSelect()} preset a top-level default
     * cell uses, so a column row gets the searchable dropdown and the
     * "— no default —" sentinel rather than its own spelling of both.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            // The value derives entirely from the sub-mappings below, so the row
            // renders neither cell of its own — absence is the whole declaration.
            'source'  => false,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) use ($field) {
                $columns = $this->mappableColumns($field);

                if (! $columns) {
                    return $b
                        ->note(['text' => Craft::t('influx', 'This Table field has no mappable columns yet.')]);
                }

                $subFields = MappingSchemaBuilder::make();

                foreach ($columns as $colId => $column) {
                    $config = [
                        'handle' => $colId,
                        'label'  => $this->columnLabel($colId, $column),
                    ];

                    if (($column['type'] ?? null) === 'select') {
                        $subFields->defaultSelect($config + ['options' => $this->columnOptions($column)]);

                        continue;
                    }

                    $subFields->text($config);
                }

                return $b->subFields([
                    'label'     => Craft::t('influx', 'Columns'),
                    'subFields' => $subFields->toArray(),
                ]);
            },
        ]);
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
            $print[] = $this->rowPrint(is_array($row) ? $row : [], $columns);
        }

        return $print;
    }

    /**
     * One row's comparable form: its mapped cells, each reduced by its column
     * type ({@see cellPrint()}), in the columns' declared order. Every value it
     * holds is a scalar or null ({@see \GlueAgency\Influx\helpers\Comparable::of()}),
     * so two prints compare with `===` — which is what lets the drill-down pair
     * rows on the very prints change detection compares
     * ({@see pairRows()}), with no encoding step in between.
     *
     * @param array<string, mixed> $cells
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @return array<string, mixed>
     */
    protected function rowPrint(array $cells, array $columns): array
    {
        $reduced = [];

        foreach ($columns as $colId => $column) {
            $reduced[$colId] = $this->cellPrint((string) ($column['type'] ?? ''), $cells[$colId] ?? null);
        }

        return $reduced;
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
     * Rows — the noun the inspectors count this row's children with.
     */
    public function childrenKind(): ?string
    {
        return 'rows';
    }

    /**
     * Per-ROW drill-down for this mapping, derived from the row set the field is
     * receiving and the one the element still held before it. Read-only: it
     * persists nothing and touches neither the parsed value nor the element, so
     * it behaves the same on a dry run (where nothing was applied) as on a real
     * one.
     *
     * The pairing is {@see Matrix::collectChildren()}'s, minus the block type
     * every step of that one scopes by — a table row has no type, so both passes
     * run over the whole row set. The EXACT pass walks the incoming rows in order
     * and lets each consume the first unconsumed stored row with an identical
     * fingerprint ({@see rowPrint()}), which reads UNCHANGED. The POSITIONAL pass
     * hands every remaining row the next unconsumed stored one as a comparison
     * partner (possibly none) and reads ADDED — the sync is full-replace, so even
     * a paired-but-different row is rewritten; the partner only supplies the
     * Current column and the per-cell changed flags. Stored rows nobody consumed
     * follow as REMOVED: in the field, not in the feed.
     *
     * A child carries no element and no title. A table row is neither, so the
     * drill-down labels it by its ordinal — which is the row's identity anyway, a
     * table being positional. Its cells are named from the field's column
     * headings instead, the only place that knows `col1` is "Label"
     * ({@see ChildResult::$labels}).
     *
     * Accepted cost, the same one Matrix accepts: this re-resolves the nodes
     * {@see parse()} already resolved. Deriving the drill-down purely from the two
     * values it is handed is what keeps it independent of whether — and how — the
     * change check ran.
     *
     * One reading worth knowing about: on a FRESH element Craft's
     * `normalizeValue()` hands back the field's configured DEFAULT rows as the
     * current value, so a would-create item shows those as rows the replace drops.
     * That is exactly what change detection sees ({@see valueDiffers()}), and the
     * drill-down deliberately doesn't disagree with it.
     *
     * @param mixed $incoming the parsed row set
     * @param mixed $current the field's value from before apply()
     * @return list<ChildResult>|null
     */
    public function collectChildren(FieldContext $context, mixed $incoming, mixed $current): ?array
    {
        if (! is_array($incoming)) {
            return null;
        }

        $columns = $this->mappedColumns($context);

        if ($columns === []) {
            return null;
        }

        $rows = array_values($incoming);
        $stored = $this->currentRows($current);

        if ($rows === [] && $stored === []) {
            return null;
        }

        $subs = $this->columnMappings($context->mapping, $columns);
        $lists = $this->resolvedLists($context, $subs);
        $labels = $this->columnLabels($columns);
        $pairing = $this->pairRows($rows, $stored, $columns);

        $children = [];

        foreach ($rows as $i => $row) {
            $partnerIndex = $pairing['partners'][$i] ?? null;
            $action = $pairing['actions'][$i];

            $children[] = new ChildResult(
                labels: $labels,
                action: $this->childActionLabel($context, $action),
                mappingResults: $this->incomingCellRows(
                    $columns,
                    $subs,
                    $lists,
                    is_array($row) ? $row : [],
                    $i,
                    $partnerIndex !== null ? $stored[$partnerIndex] : null,
                    $action,
                ),
            );
        }

        foreach ($pairing['removed'] as $index) {
            $children[] = new ChildResult(
                labels: $labels,
                action: $this->childActionLabel($context, ChildAction::REMOVED),
                mappingResults: $this->removedCellRows($subs, $stored[$index]),
            );
        }

        return array_slice($children, 0, self::CHILD_RESULT_LIMIT);
    }

    /**
     * Pair incoming rows with stored ones — exact fingerprints first, then
     * positionally ({@see collectChildren()} documents why). Yields, per incoming
     * index, its partner row's index (or null) and its action, plus the indexes of
     * the stored rows nobody consumed, in stored order.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $stored
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @return array{partners: array<int, ?int>, actions: array<int, ChildAction>, removed: list<int>}
     */
    protected function pairRows(array $rows, array $stored, array $columns): array
    {
        $incomingPrints = [];

        foreach ($rows as $row) {
            $incomingPrints[] = $this->rowPrint(is_array($row) ? $row : [], $columns);
        }

        $storedPrints = [];

        foreach ($stored as $row) {
            $storedPrints[] = $this->rowPrint($row, $columns);
        }

        $partners = [];
        $actions = [];
        $consumed = [];

        foreach ($incomingPrints as $i => $print) {
            $match = $this->firstUnconsumed($storedPrints, $consumed, $print);

            if ($match === null) {
                continue;
            }

            $consumed[$match] = true;
            $partners[$i] = $match;
            $actions[$i] = ChildAction::UNCHANGED;
        }

        foreach (array_keys($incomingPrints) as $i) {
            if (isset($actions[$i])) {
                continue;
            }

            $match = $this->nextUnconsumed($storedPrints, $consumed);

            if ($match !== null) {
                $consumed[$match] = true;
            }

            $partners[$i] = $match;
            $actions[$i] = ChildAction::ADDED;
        }

        return [
            'partners' => $partners,
            'actions'  => $actions,
            'removed'  => array_values(array_diff(array_keys($stored), array_keys($consumed))),
        ];
    }

    /**
     * The index of the first stored row nothing has claimed yet — the positional
     * pass's step. {@see Field::firstUnconsumed()} matches on a needle, which the
     * type-scoped Matrix pass needs and this one doesn't: any unconsumed row is a
     * valid partner for the row at this position.
     *
     * @param list<mixed> $values
     * @param array<int, true> $consumed
     */
    protected function nextUnconsumed(array $values, array $consumed): ?int
    {
        foreach (array_keys($values) as $index) {
            if (! isset($consumed[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The mapped cells of one incoming row, in the columns' DECLARED order — the
     * table read left to right, rather than the order the mapping happens to
     * list them in.
     *
     * `$lists` is indexed by row position, the way {@see parse()} zips it: a
     * column whose list doesn't reach this row contributed the fixed-width filler
     * null rather than a value, so that cell reports unaddressed instead of as a
     * bare null — and never as changed.
     *
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @param array<string, FieldMapping> $subs mapped colId → its sub-mapping
     * @param array<string, list<mixed>> $lists mapped colId → per-row feed values
     * @param array<string, mixed> $row the parsed row
     * @param array<string, mixed>|null $partner the stored row this one compares against
     * @return list<MappingResult>
     */
    protected function incomingCellRows(
        array $columns,
        array $subs,
        array $lists,
        array $row,
        int $index,
        ?array $partner,
        ChildAction $action,
    ): array {
        $results = [];

        foreach ($subs as $colId => $sub) {
            $values = $lists[$colId] ?? [];
            $unaddressed = ! array_key_exists($index, $values);
            $parsed = $row[$colId] ?? null;
            $currentValue = $partner !== null ? ($partner[$colId] ?? null) : null;

            $results[] = new MappingResult(
                handle: $colId,
                node: $sub->node,
                default: $sub->default,
                native: false,
                rawValue: $unaddressed ? null : $values[$index],
                parsedValue: $parsed,
                currentValue: $currentValue,
                changed: ! $unaddressed && $this->cellChanged(
                    $action,
                    $partner,
                    (string) ($columns[$colId]['type'] ?? ''),
                    $parsed,
                    $currentValue,
                ),
                unaddressed: $unaddressed,
            );
        }

        return $results;
    }

    /**
     * The mapped cells of a row the replace drops: the same walk, but there is no
     * feed side to show — raw and parsed stay null and `changed` stays
     * unevaluated.
     *
     * @param array<string, FieldMapping> $subs
     * @param array<string, mixed> $cells
     * @return list<MappingResult>
     */
    protected function removedCellRows(array $subs, array $cells): array
    {
        $results = [];

        foreach ($subs as $colId => $sub) {
            $results[] = new MappingResult(
                handle: $colId,
                node: $sub->node,
                default: $sub->default,
                native: false,
                rawValue: null,
                currentValue: $cells[$colId] ?? null,
                changed: null,
            );
        }

        return $results;
    }

    /**
     * Whether one incoming cell differs from its partner row's. An UNCHANGED row
     * was fingerprint-identical, so nothing in it can have changed; a paired one
     * compares through the column type's own reduction ({@see cellPrint()}), the
     * way change detection does; an unpaired one has nothing to compare against,
     * so any value it carries is new.
     *
     * @param array<string, mixed>|null $partner
     */
    protected function cellChanged(
        ChildAction $action,
        ?array $partner,
        string $type,
        mixed $parsed,
        mixed $current,
    ): bool {
        if ($action === ChildAction::UNCHANGED) {
            return false;
        }

        if ($partner === null) {
            return $parsed !== null;
        }

        return $this->cellPrint($type, $parsed) !== $this->cellPrint($type, $current);
    }

    /**
     * The rows the element holds NOW: the field's normalized value, a list of
     * cell maps keyed by column id — or none when it holds nothing, which is what
     * Craft's `normalizeValue()` returns for an empty table.
     *
     * @return list<array<string, mixed>>
     */
    protected function currentRows(mixed $current): array
    {
        if (! is_array($current)) {
            return [];
        }

        $rows = [];

        foreach ($current as $row) {
            $rows[] = is_array($row) ? $row : [];
        }

        return $rows;
    }

    /**
     * The active sub-mapping behind each mapped column, keyed by column id in the
     * columns' declared order — {@see mappedColumns()} paired with the mappings
     * that produced it.
     *
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @return array<string, FieldMapping>
     */
    protected function columnMappings(FieldMapping $mapping, array $columns): array
    {
        $byHandle = [];

        foreach ($this->activeColumnMappings($mapping) as $sub) {
            $byHandle[$sub->handle] = $sub;
        }

        $subs = [];

        foreach (array_keys($columns) as $colId) {
            if (isset($byHandle[$colId])) {
                $subs[$colId] = $byHandle[$colId];
            }
        }

        return $subs;
    }

    /**
     * Each mapped column's per-row value list — the same resolve-and-
     * {@see valueList()} step {@see parse()} zips into rows, so indexing a list by
     * a row's position recovers the feed value that cell was built from. A column
     * whose node resolves to nothing gets an empty list, exactly as it does there.
     *
     * @param array<string, FieldMapping> $subs
     * @return array<string, list<mixed>>
     */
    protected function resolvedLists(FieldContext $context, array $subs): array
    {
        $lists = [];

        foreach ($subs as $colId => $sub) {
            $resolved = $sub->resolve($context->item);
            $lists[$colId] = $resolved === null ? [] : $this->valueList($resolved);
        }

        return $lists;
    }

    /**
     * What each mapped cell row is called: its column's heading
     * ({@see columnLabel()}). A table row's cells are columns, not layout fields,
     * so the presenter has no layout to name them from and the child carries this
     * map instead ({@see ChildResult::$labels}).
     *
     * @param array<string, array<string, mixed>> $columns mapped colId → column
     * @return array<string, string>
     */
    protected function columnLabels(array $columns): array
    {
        $labels = [];

        foreach ($columns as $colId => $column) {
            $labels[$colId] = $this->columnLabel($colId, $column);
        }

        return $labels;
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
        return $this->filterActive($mapping->subMappings());
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
     * A select column's options as schema select options — the column's own
     * values only, since the "nothing picked" row rides the node as
     * {@see MappingSchemaBuilder::defaultSelect()}'s sentinel. Labels are
     * site-translated the way Craft translates them on the field's own input.
     *
     * @param array<string, mixed> $column
     * @return list<array{value: string, label: string}>
     */
    protected function columnOptions(array $column): array
    {
        $options = [];

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
