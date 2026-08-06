<?php

namespace GlueAgency\Influx\integrations\verbb\tablemaker;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\TableCells;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for Verbb's Table Maker field.
 *
 * The whole premise of the field is that an entry defines its own columns and its
 * own values, however many or few it wants — so a mapping that declared the
 * columns would turn a per-entry structure into a fixed one for every item the
 * feed carries. The row therefore takes ONE source node holding the whole table,
 * and the columns come out of the feed with the values.
 *
 * That means the feed has to speak a fixed shape, and this is it:
 *
 *   "table": {
 *       "columns": ["this", "that", "other"],
 *       "values": [
 *           [1, 2, 3],
 *           [4, 5, 6],
 *           [null, 7, 8],
 *           ["pizza", "sausage"]
 *       ]
 *   }
 *
 * A column may be a bare string — its label — or an object carrying `label` plus
 * any of `type`, `align` and `width`:
 *
 *   "columns": [
 *       { "label": "this",  "width": 50 },
 *       { "label": "that",  "width": 25, "type": "number" },
 *       { "label": "other", "width": 25, "align": "right" }
 *   ]
 *
 * Rows are positional against that list. A row shorter than the column set pads
 * with empty cells and an explicit null IS an empty cell, so a ragged table is
 * expressible; a row longer than the column set has its tail dropped, since Table
 * Maker reads a cell as `$row[$i]` against `$columns[$i]` and a cell with no
 * column has nowhere to be stored.
 *
 * `select` is not an accepted column type, and an unrecognised one falls back to
 * single-line text. A dropdown column's cell must be one of a closed set of
 * options the feed has no way to declare, so accepting one would store a value
 * the CP can't render back.
 *
 * Sync semantics are full-replace: the feed owns the whole table, columns
 * included. {@see valueDiffers()} compares columns and rows together — a renamed
 * heading IS a change to the field — with cells reduced by their column type
 * ({@see TableCells::cellPrint()}), because a CP round-trip stores a checkbox as a
 * real bool and a date as a DateTime while the feed carries "yes" and an ISO
 * string. It never compares `table`, the rendered HTML preview Table Maker
 * regenerates from the other two on every read.
 *
 * A Table Maker row reports as one value in the log rather than drilling down per
 * row, as an {@see \GlueAgency\Influx\fields\Addresses} mapping does.
 *
 * Keyed by class string, so an install without the plugin never touches it.
 */
class TableMakerField extends Field
{
    use TableCells;

    /** The default a column with no usable `type` takes. */
    public const DEFAULT_TYPE = 'singleline';

    /**
     * The Craft column types a feed may name, `value => label`.
     *
     * Table Maker's own list minus `select` (see the class docblock) and minus
     * `heading`, which Craft offers for the row-header pattern a feed-written
     * table has no use for. Labels are Craft's own strings — the same ones the
     * field's editor shows — and they're here for the operator-facing note the
     * row renders, not for a control.
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
     * One source node and nothing else.
     *
     * No default cell: a default is one literal value a sync falls back to, and
     * what this field takes is a whole table — there is no text box that could
     * express one. The extras hold the format contract instead, because a feed
     * shipping the wrong shape is the only way this row can fail and the operator
     * has no other way to learn what it wants.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => false,
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->note([
                'text' => Craft::t('influx', 'Map a node holding the whole table: an object with a “columns” list and a “values” list of rows. A column is a label, or an object with “label” plus any of “type”, “align” and “width”. Rows are positional against the columns; a short row leaves the rest empty. Column types: {types}.', [
                    'types' => implode(', ', array_keys(static::columnTypes())),
                ]),
            ]),
        ]);
    }

    /**
     * The feed's table as Table Maker stores one.
     *
     * A node resolving to something that isn't a table clears the field rather
     * than throwing: `addressed()` was true, so the feed is authoritative, and an
     * empty table is what "this item has none" looks like. The same goes for a
     * table with no columns — there is nothing for the values to hang on.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<list<mixed>>}
     */
    public function parse(FieldContext $context): mixed
    {
        $value = $context->mapping->resolve($context->item);
        $columns = is_array($value) ? static::columnsFrom($value['columns'] ?? []) : [];

        if ($columns === []) {
            return ['columns' => [], 'rows' => []];
        }

        $rows = [];

        foreach (is_array($value['values'] ?? null) ? $value['values'] : [] as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            $built = [];

            foreach ($columns as $index => $column) {
                // Positional, padded, and truncated: a cell past the last column
                // has nowhere to be stored, and a missing one is empty rather
                // than a reason to shift its neighbours left.
                $built[] = array_key_exists($index, $cells)
                    ? $this->coerceCell($column['type'], $cells[$index])
                    : null;
            }

            $rows[] = $built;
        }

        return ['columns' => static::storableColumns($columns), 'rows' => $rows];
    }

    /**
     * Compare columns and rows, never the derived `table` preview.
     *
     * Each side is reduced against its OWN columns, so a renamed heading or a
     * retyped column shows up as the change it is — and when the columns do match,
     * both sides reduce their cells by the same types, which is what stops a
     * stored bool comparing unequal to the feed's "yes".
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        return $this->fingerprint($current) !== $this->fingerprint($incoming);
    }

    /**
     * One side's comparable form. Both sides are already in the STORED shape by
     * the time they get here — the incoming one came out of {@see parse()} — so
     * this reads Table Maker's keys, not the feed's.
     *
     * @return array{columns: list<array<string, string>>, rows: list<list<mixed>>}
     */
    protected function fingerprint(mixed $value): array
    {
        if (! is_array($value)) {
            return ['columns' => [], 'rows' => []];
        }

        $columns = [];
        $types = [];

        foreach (is_array($value['columns'] ?? null) ? array_values($value['columns']) : [] as $column) {
            $column = is_array($column) ? $column : [];
            $types[] = (string) ($column['type'] ?? self::DEFAULT_TYPE);
            $columns[] = [
                'heading' => trim((string) ($column['heading'] ?? '')),
                'type'    => (string) ($column['type'] ?? self::DEFAULT_TYPE),
                'align'   => (string) ($column['align'] ?? ''),
                'width'   => (string) ($column['width'] ?? ''),
            ];
        }

        $rows = [];

        foreach (is_array($value['rows'] ?? null) ? $value['rows'] : [] as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            $reduced = [];

            foreach ($types as $index => $type) {
                $reduced[] = $this->cellPrint($type, $cells[$index] ?? null);
            }

            $rows[] = $reduced;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /**
     * The feed's `columns` as a clean list of `{heading, type, align, width}`.
     *
     * A bare string is its label — the terse form for the common table nobody
     * needs to type or align. Anything without a usable label is dropped: an
     * unlabelled column still occupies a position every row counts against, but a
     * feed that ships one has a bug, and keeping it would silently widen the
     * table with a blank heading.
     *
     * @return list<array{heading: string, type: string, align: string, width: string}>
     */
    protected static function columnsFrom(mixed $columns): array
    {
        $clean = [];

        foreach (is_array($columns) ? $columns : [] as $column) {
            if (is_scalar($column)) {
                $column = ['label' => $column];
            }

            if (! is_array($column)) {
                continue;
            }

            $heading = trim((string) ($column['label'] ?? ''));

            if ($heading === '') {
                continue;
            }

            $type = (string) ($column['type'] ?? '');
            $clean[] = [
                'heading' => $heading,
                'type'    => isset(static::columnTypes()[$type]) ? $type : self::DEFAULT_TYPE,
                'align'   => (string) ($column['align'] ?? ''),
                // A feed may ship a width as a number; Table Maker stores a string.
                'width' => (string) ($column['width'] ?? ''),
            ];
        }

        return $clean;
    }

    /**
     * The columns in Table Maker's own key order — the shape its `serializeValue()`
     * round-trips and its editor reads back.
     *
     * @param list<array{heading: string, type: string, align: string, width: string}> $columns
     * @return list<array<string, mixed>>
     */
    protected static function storableColumns(array $columns): array
    {
        return array_map(
            static fn(array $column): array => [
                'heading' => $column['heading'],
                'width'   => $column['width'],
                'align'   => $column['align'],
                'type'    => $column['type'],
            ],
            $columns,
        );
    }
}
