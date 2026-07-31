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

    // -- fixtures -------------------------------------------------------------

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
    protected function context(RemoteItem $item, array $fields, ?array $columns = null): FieldContext
    {
        return new FieldContext(
            craftField: $this->fakeField($columns ?? $this->textColumns()),
            handle: 'specs',
            mapping: FieldMapping::fromConfig('specs', ['fields' => $fields]),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
