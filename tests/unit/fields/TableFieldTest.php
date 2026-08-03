<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\Table as CraftTableField;
use DateTime;
use DateTimeZone;
use GlueAgency\Influx\fields\Table;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Table mapping strategy — the no-boot half. The Craft
 * field is a mock carrying nothing but its `columns` config, which is all the
 * strategy reads off it, so the schema, the index-zip, the per-column-type
 * coercion and the change detection are exercised without a running Craft.
 *
 * Mappings live under the flat `fields` channel, one sub-mapping per COLUMN ID
 * ({@see FieldMapping::subMappings()}), whose node paths are absolute (resolved
 * against the top-level item) and index-zipped into rows.
 */
class TableFieldTest extends Unit
{
    // -- schema ---------------------------------------------------------------

    public function testEachMappableColumnGetsARowKeyedByItsColumnId(): void
    {
        $nodes = $this->strategy()->exposedSchema($this->fakeField([
            'col1' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            'col2' => ['heading' => '', 'handle' => 'value', 'type' => 'number'],
            'col3' => ['heading' => '', 'handle' => '', 'type' => 'url'],
        ]))->toArray();

        $this->assertCount(1, $nodes);
        $this->assertSame(SchemaBuilder::SUB_FIELDS, $nodes[0]['type']);
        $this->assertSame('fields', $nodes[0]['handle']);

        $subFields = $nodes[0]['subFields'];
        $this->assertSame(['col1', 'col2', 'col3'], array_column($subFields, 'handle'));
        // Heading, else handle, else the column id — a column may carry neither.
        $this->assertSame(['Label', 'value', 'col3'], array_column($subFields, 'label'));
        $this->assertSame(['text', 'text', 'text'], array_column($subFields, 'type'));
    }

    public function testHeadingColumnsAreNotMappable(): void
    {
        // Craft's serializers skip a heading column and normalizeValue()
        // overwrites its cells from the field defaults — a mapped value could
        // never land.
        $nodes = $this->strategy()->exposedSchema($this->fakeField([
            'col1' => ['heading' => 'Section', 'handle' => '', 'type' => 'heading'],
            'col2' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]))->toArray();

        $this->assertSame(['col2'], array_column($nodes[0]['subFields'], 'handle'));
    }

    public function testASelectColumnOffersItsOwnOptions(): void
    {
        $nodes = $this->strategy()->exposedSchema($this->fakeField([
            'col1' => [
                'heading' => 'Size',
                'handle'  => 'size',
                'type'    => 'select',
                'options' => [
                    ['value' => 's', 'label' => 'Small'],
                    ['value' => 'l', 'label' => 'Large'],
                    // Option rows without a value carry nothing storable.
                    ['label' => 'Orphan'],
                ],
            ],
        ]))->toArray();

        $row = $nodes[0]['subFields'][0];
        $this->assertSame(SchemaBuilder::SELECT, $row['type']);
        $this->assertSame([
            ['value' => '',  'label' => '—'],
            ['value' => 's', 'label' => 'Small'],
            ['value' => 'l', 'label' => 'Large'],
        ], $row['options']);
    }

    public function testAFieldWithoutMappableColumnsRendersANote(): void
    {
        $nodes = $this->strategy()->exposedSchema($this->fakeField([]))->toArray();

        $this->assertCount(1, $nodes);
        $this->assertSame(SchemaBuilder::NOTE, $nodes[0]['type']);
    }

    // -- parse ----------------------------------------------------------------

    public function testMappedColumnsIndexZipIntoRows(): void
    {
        $item = new RemoteItem([
            'specs' => [
                ['label' => 'Width', 'value' => 10],
                ['label' => 'Height', 'value' => 20],
            ],
        ]);

        $rows = $this->strategy()->parse($this->context($item, [
            'col1' => ['node' => 'specs.label'],
            'col2' => ['node' => 'specs.value'],
        ]));

        $this->assertSame([
            ['col1' => 'Width',  'col2' => 10],
            ['col1' => 'Height', 'col2' => 20],
        ], $rows);
    }

    public function testScalarValuesCollapseToASingleRow(): void
    {
        $item = new RemoteItem(['label' => 'Width', 'value' => 10]);

        $rows = $this->strategy()->parse($this->context($item, [
            'col1' => ['node' => 'label'],
            'col2' => ['node' => 'value'],
        ]));

        $this->assertSame([['col1' => 'Width', 'col2' => 10]], $rows);
    }

    public function testRaggedListsLeaveTrailingCellsNull(): void
    {
        // The rows are a fixed-width record: serializeValue() reads every cell
        // as $row[$colId] un-coalesced, so a short list yields an explicit null.
        $item = new RemoteItem(['a' => ['x', 'y', 'z'], 'b' => [1, 2]]);

        $rows = $this->strategy()->parse($this->context($item, [
            'col1' => ['node' => 'a'],
            'col2' => ['node' => 'b'],
        ]));

        $this->assertSame([
            ['col1' => 'x', 'col2' => 1],
            ['col1' => 'y', 'col2' => 2],
            ['col1' => 'z', 'col2' => null],
        ], $rows);
    }

    public function testAColumnTheFieldNoLongerDeclaresIsSkipped(): void
    {
        // A removed column isn't a structural error — the mapping outlived it,
        // and the columns that are left still have a table to write into.
        $item = new RemoteItem(['a' => ['x'], 'gone' => ['y']]);

        $rows = $this->strategy()->parse($this->context($item, [
            'col1'  => ['node' => 'a'],
            'col99' => ['node' => 'gone'],
        ]));

        $this->assertSame([['col1' => 'x']], $rows);
    }

    public function testEveryColumnResolvingToNothingIsAnExplicitClear(): void
    {
        $item = new RemoteItem(['a' => '']);

        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        // addressedBy is true for an explicit empty-string node value…
        $this->assertTrue($strategy->addressed($context));
        // …but resolve() returns null for it, so no row contributes.
        $this->assertSame([], $strategy->parse($context));
    }

    public function testInactiveColumnMappingsNeverContribute(): void
    {
        $item = new RemoteItem(['a' => ['x']]);

        $rows = $this->strategy()->parse($this->context($item, [
            'col1' => ['node' => 'a'],
            // No node, no useDefault — not wired to a source at all.
            'col2' => [],
        ]));

        $this->assertSame([['col1' => 'x']], $rows);
    }

    public function testFlagColumnsCoerceFeedSpellingsToBooleans(): void
    {
        $item = new RemoteItem(['flags' => ['yes', 'no', 'TRUE']]);
        $columns = ['col1' => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox']];

        $rows = $this->strategy()->parse($this->context($item, ['col1' => ['node' => 'flags']], $columns));

        $this->assertSame([['col1' => true], ['col1' => false], ['col1' => true]], $rows);
    }

    public function testTextColumnsAreTrimmed(): void
    {
        $item = new RemoteItem(['a' => ['  padded  '], 'b' => ["  multi\n"]]);
        $columns = [
            'col1' => ['heading' => 'One', 'handle' => 'one', 'type' => 'singleline'],
            'col2' => ['heading' => 'Two', 'handle' => 'two', 'type' => 'multiline'],
        ];

        $rows = $this->strategy()->parse($this->context($item, [
            'col1' => ['node' => 'a'],
            'col2' => ['node' => 'b'],
        ], $columns));

        $this->assertSame([['col1' => 'padded', 'col2' => 'multi']], $rows);
    }

    // -- addressed ------------------------------------------------------------

    public function testAddressedIsTrueWhenOneColumnAddressesTheItem(): void
    {
        $item = new RemoteItem(['specs' => [['label' => 'Width']]]);
        $context = $this->context($item, [
            'col1' => ['node' => 'specs.label'],
            'col2' => ['node' => 'specs.value'],
        ]);

        $this->assertTrue($this->strategy()->addressed($context));
    }

    public function testAddressedIsFalseWhenNoColumnAddressesTheItem(): void
    {
        $item = new RemoteItem(['other' => 'x']);
        $context = $this->context($item, ['col1' => ['node' => 'specs.label']]);

        $this->assertFalse($this->strategy()->addressed($context));
    }

    // -- change detection -----------------------------------------------------

    public function testValueDiffersIsFalseForIdenticalCurrentAndIncoming(): void
    {
        $item = new RemoteItem(['a' => ['x', 'y']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);
        // A stored row carries the column's handle alongside its id — the
        // fingerprint reads the id, the way the serializers do.
        $current = [['col1' => 'x', 'label' => 'x'], ['col1' => 'y', 'label' => 'y']];

        $this->assertFalse($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueWhenAMappedCellChanges(): void
    {
        $item = new RemoteItem(['a' => ['x', 'y']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);

        $this->assertTrue($strategy->exposedValueDiffers(
            $context,
            [['col1' => 'x'], ['col1' => 'CHANGED']],
            $incoming,
        ));
    }

    public function testValueDiffersIsTrueOnRowCountMismatch(): void
    {
        $item = new RemoteItem(['a' => ['x', 'y']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);

        $this->assertTrue($strategy->exposedValueDiffers($context, [['col1' => 'x']], $incoming));
    }

    public function testAnUnmappedColumnsStoredValueIsNotAChange(): void
    {
        // The replace writes an unmapped column empty, so comparing it would
        // make every sync of a partially-mapped table look like a change.
        $item = new RemoteItem(['a' => ['x']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);

        $this->assertFalse($strategy->exposedValueDiffers(
            $context,
            [['col1' => 'x', 'col2' => 'whatever the editor typed']],
            $incoming,
        ));
    }

    public function testADateCellComparesByInstant(): void
    {
        // The stored side is the field's normalized DateTime while the feed
        // carries an ISO string — without a per-type reduction the cell would
        // read as changed on every single sync.
        $item = new RemoteItem(['at' => '2024-03-02T10:00:00+00:00']);
        $columns = ['col1' => ['heading' => 'When', 'handle' => 'when', 'type' => 'date']];
        $context = $this->context($item, ['col1' => ['node' => 'at']], $columns);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);

        $sameInstant = [['col1' => new DateTime('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels'))]];
        $this->assertFalse($strategy->exposedValueDiffers($context, $sameInstant, $incoming));

        $oneSecondLater = [['col1' => new DateTime('2024-03-02 10:00:01', new DateTimeZone('UTC'))]];
        $this->assertTrue($strategy->exposedValueDiffers($context, $oneSecondLater, $incoming));
    }

    public function testAFlagCellComparesAsABoolean(): void
    {
        $item = new RemoteItem(['on' => ['1', '0']]);
        $columns = ['col1' => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox']];
        $context = $this->context($item, ['col1' => ['node' => 'on']], $columns);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);

        $this->assertFalse($strategy->exposedValueDiffers(
            $context,
            [['col1' => true], ['col1' => false]],
            $incoming,
        ));
        $this->assertTrue($strategy->exposedValueDiffers(
            $context,
            [['col1' => true], ['col1' => true]],
            $incoming,
        ));
    }

    public function testAClearedFieldDiffersFromIncomingRows(): void
    {
        $item = new RemoteItem(['a' => ['x']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        $this->assertTrue($strategy->exposedValueDiffers($context, null, $strategy->parse($context)));
        $this->assertFalse($strategy->exposedValueDiffers($context, null, []));
    }

    // -- per-row drill-down ---------------------------------------------------

    public function testChildrenKindIsRows(): void
    {
        $this->assertSame('rows', $this->strategy()->childrenKind());
    }

    public function testIdenticalRowsReadAsUnchangedChildren(): void
    {
        $item = new RemoteItem(['specs' => [['label' => 'Width', 'value' => 10]]]);
        $context = $this->context($item, [
            'col1' => ['node' => 'specs.label'],
            'col2' => ['node' => 'specs.value'],
        ]);
        $strategy = $this->strategy();

        $incoming = $strategy->parse($context);
        $children = $strategy->collectChildren($context, $incoming, [
            // A stored row carries the column's handle beside its id; the
            // fingerprint reads the id, the way the serializers do.
            ['col1' => 'Width', 'label' => 'Width', 'col2' => 10, 'value' => 10],
        ]);

        $this->assertCount(1, $children);
        $this->assertSame('unchanged', $children[0]->action);
        // A table row is no element and has no name of its own — the drill-down
        // labels it by its ordinal.
        $this->assertNull($children[0]->title);
        $this->assertNull($children[0]->element);
        $this->assertNull($children[0]->labelElement);

        $rows = $this->rowsByHandle($children[0]);
        $this->assertSame(['col1', 'col2'], array_keys($rows));
        $this->assertSame('specs.label', $rows['col1']->node);
        $this->assertSame('Width', $rows['col1']->rawValue);
        $this->assertSame('Width', $rows['col1']->parsedValue);
        $this->assertSame('Width', $rows['col1']->currentValue);
        $this->assertFalse($rows['col1']->changed);
        $this->assertFalse($rows['col1']->native);
        $this->assertFalse($rows['col2']->changed);
    }

    public function testTheChildNamesItsCellsAfterTheColumnHeadings(): void
    {
        // A table row's cells are COLUMNS, not layout fields, so the presenter has
        // no layout to name them from — the child brings the map itself, for the
        // mapped columns it shows rows for.
        $item = new RemoteItem(['a' => 'x', 'b' => 'y']);
        $context = $this->context($item, [
            'col1' => ['node' => 'a'],
            'col2' => ['node' => 'b'],
        ]);

        $children = $this->strategy()->collectChildren($context, [['col1' => 'x', 'col2' => 'y']], []);

        $this->assertSame(['col1' => 'Label', 'col2' => 'Value'], $children[0]->labels);

        // An unmapped column has no row to name.
        $oneColumn = $this->context($item, ['col1' => ['node' => 'a']]);
        $children = $this->strategy()->collectChildren($oneColumn, [['col1' => 'x']], []);

        $this->assertSame(['col1' => 'Label'], $children[0]->labels);
    }

    public function testACellsRowsFollowTheColumnsDeclaredOrder(): void
    {
        // The mapping lists the second column first; the drill-down still reads
        // left to right, the way the editor sees the table.
        $item = new RemoteItem(['a' => 'x', 'b' => 'y']);
        $context = $this->context($item, [
            'col2' => ['node' => 'b'],
            'col1' => ['node' => 'a'],
        ]);
        $strategy = $this->strategy();

        $children = $strategy->collectChildren($context, $strategy->parse($context), []);

        $this->assertSame(['col1', 'col2'], array_keys($this->rowsByHandle($children[0])));
    }

    public function testAChangedCellReadsAsAnAddAgainstItsPartner(): void
    {
        // Same position, one differing cell: full-replace means the row is written
        // anew, and the stored row is only there to fill the Current column and
        // flag which cell moved.
        $item = new RemoteItem(['specs' => [['label' => 'Width', 'value' => 10]]]);
        $context = $this->context($item, [
            'col1' => ['node' => 'specs.label'],
            'col2' => ['node' => 'specs.value'],
        ], dryRun: true);
        $strategy = $this->strategy();

        $children = $strategy->collectChildren($context, $strategy->parse($context), [
            ['col1' => 'Width', 'col2' => 99],
        ]);

        $this->assertCount(1, $children);
        $this->assertSame('would-add', $children[0]->action);

        $rows = $this->rowsByHandle($children[0]);
        $this->assertTrue($rows['col2']->changed);
        $this->assertSame(99, $rows['col2']->currentValue);
        $this->assertFalse($rows['col1']->changed, 'Only the differing cell is flagged.');
    }

    public function testAnIncomingRowWithoutAPartnerHasNoCurrentValues(): void
    {
        $item = new RemoteItem(['a' => ['x', 'y']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']], dryRun: true);
        $strategy = $this->strategy();

        // The first row is the stored one; the second has nothing left to pair
        // with.
        $children = $strategy->collectChildren($context, $strategy->parse($context), [['col1' => 'x']]);

        $this->assertSame(['unchanged', 'would-add'], array_column($children, 'action'));

        $rows = $this->rowsByHandle($children[1]);
        $this->assertNull($rows['col1']->currentValue);
        $this->assertTrue($rows['col1']->changed, 'Nothing to compare against — a parsed value is new.');
    }

    public function testALeftoverStoredRowReadsAsARemoval(): void
    {
        $item = new RemoteItem(['a' => ['x']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']], dryRun: true);
        $strategy = $this->strategy();

        $children = $strategy->collectChildren($context, $strategy->parse($context), [
            ['col1' => 'x'],
            ['col1' => 'dropped'],
        ]);

        $this->assertSame(['unchanged', 'would-remove'], array_column($children, 'action'));

        $rows = $this->rowsByHandle($children[1]);
        $this->assertSame('dropped', $rows['col1']->currentValue);
        $this->assertNull($rows['col1']->rawValue);
        $this->assertNull($rows['col1']->parsedValue);
        $this->assertNull($rows['col1']->changed, 'A dropped row has no feed side to compare.');
    }

    public function testAPerIndexMissingCellIsUnaddressed(): void
    {
        // Ragged lists: the third row never gets a `col2` value, so that cell is
        // the fixed-width filler — amber, not a silent null, and not a change.
        $item = new RemoteItem(['a' => ['x', 'y', 'z'], 'b' => [1, 2]]);
        $context = $this->context($item, [
            'col1' => ['node' => 'a'],
            'col2' => ['node' => 'b'],
        ]);
        $strategy = $this->strategy();

        $children = $strategy->collectChildren($context, $strategy->parse($context), []);

        $this->assertCount(3, $children);

        $rows = $this->rowsByHandle($children[2]);
        $this->assertTrue($rows['col2']->unaddressed);
        $this->assertFalse($rows['col2']->changed);
        $this->assertNull($rows['col2']->rawValue);
        $this->assertNull($rows['col2']->parsedValue);
        $this->assertFalse($rows['col1']->unaddressed);
        $this->assertTrue($rows['col1']->changed);
        $this->assertSame('z', $rows['col1']->rawValue);
    }

    public function testACellComparesByItsColumnType(): void
    {
        // The stored cell is a real DateTime while the feed carries an ISO string:
        // without the per-type reduction every paired row would flag it as moved.
        $item = new RemoteItem(['at' => ['2024-03-02T10:00:00+00:00'], 'a' => ['x']]);
        $columns = [
            'col1' => ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
            'col2' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ];
        $context = $this->context($item, [
            'col1' => ['node' => 'at'],
            'col2' => ['node' => 'a'],
        ], $columns, dryRun: true);
        $strategy = $this->strategy();

        $children = $strategy->collectChildren($context, $strategy->parse($context), [[
            'col1' => new DateTime('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels')),
            'col2' => 'MOVED',
        ]]);

        $rows = $this->rowsByHandle($children[0]);
        $this->assertFalse($rows['col1']->changed, 'Same instant, spelled differently.');
        $this->assertTrue($rows['col2']->changed);
    }

    public function testRealRunChildrenCarryCommittedActionLabels(): void
    {
        $item = new RemoteItem(['a' => ['x', 'new']]);
        $stored = [['col1' => 'x']];

        $real = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();
        $children = $strategy->collectChildren($real, $strategy->parse($real), $stored);
        $this->assertSame(['unchanged', 'added'], array_column($children, 'action'));

        $dry = $this->context($item, ['col1' => ['node' => 'a']], dryRun: true);
        $children = $strategy->collectChildren($dry, $strategy->parse($dry), $stored);
        $this->assertSame(['unchanged', 'would-add'], array_column($children, 'action'));
    }

    public function testThereIsNothingToDrillIntoWithoutRowsOnEitherSide(): void
    {
        $item = new RemoteItem(['a' => ['x']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);
        $strategy = $this->strategy();

        // Neither the feed nor the field holds a row…
        $this->assertNull($strategy->collectChildren($context, [], null));
        // …the field was left untouched (no parsed row set at all)…
        $this->assertNull($strategy->collectChildren($context, null, [['col1' => 'x']]));
        // …and the row maps no column.
        $bare = $this->context($item, []);
        $this->assertNull($strategy->collectChildren($bare, [['col1' => 'x']], []));
    }

    public function testChildrenAreCappedAtTheResultLimit(): void
    {
        $item = new RemoteItem(['a' => ['x']]);
        $context = $this->context($item, ['col1' => ['node' => 'a']]);

        // Handed straight to the derivation: a feed that fans out this far is the
        // case the cap exists for, and parse()'s zip isn't what's under test.
        $incoming = [];

        for ($i = 1; $i <= 120; $i++) {
            $incoming[] = ['col1' => 'row ' . $i];
        }

        $this->assertCount(100, $this->strategy()->collectChildren($context, $incoming, []));
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * The child's cell rows keyed by column id, for assertions that name one.
     *
     * @return array<string, MappingResult>
     */
    protected function rowsByHandle(ChildResult $child): array
    {
        $rows = [];

        foreach ($child->mappingResults as $result) {
            $rows[$result->handle] = $result;
        }

        return $rows;
    }

    /**
     * The strategy under test, with its schema builder and its comparison
     * exposed for direct assertions.
     */
    protected function strategy(): Table
    {
        return new class() extends Table {
            public function exposedSchema(CraftTableField $field): SchemaBuilder
            {
                return $this->schema($field);
            }

            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }
        };
    }

    /**
     * A Craft Table field carrying the given column config — the one thing the
     * strategy reads off it.
     *
     * @param array<string, array<string, mixed>> $columns colId → column, in
     * declared order
     */
    protected function fakeField(array $columns): CraftTableField
    {
        $field = $this->createMock(CraftTableField::class);
        $field->columns = $columns;

        return $field;
    }

    /** The recurring two-text-column fixture. */
    protected function textColumns(): array
    {
        return [
            'col1' => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            'col2' => ['heading' => 'Value', 'handle' => 'value', 'type' => 'number'],
        ];
    }

    /**
     * A Table top-level FieldContext. `$fields` is the per-column sub-mapping
     * map ({colId: {node, default, ...}}), wrapped into the mapping's flat
     * `fields` channel; `$columns` defaults to the two-text-column fixture.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array<string, mixed>>|null $columns
     */
    protected function context(
        RemoteItem $item,
        array $fields,
        ?array $columns = null,
        bool $dryRun = false,
    ): FieldContext {
        return new FieldContext(
            craftField: $this->fakeField($columns ?? $this->textColumns()),
            handle: 'specs',
            mapping: FieldMapping::fromConfig('specs', ['fields' => $fields]),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            dryRun: $dryRun,
        );
    }
}
