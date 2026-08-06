<?php

namespace GlueAgency\Influx\Tests\unit\integrations\verbb\tablemaker;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\PlainText;
use GlueAgency\Influx\integrations\verbb\tablemaker\TableMakerField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Table Maker strategy.
 *
 * The premise of the field is that an entry defines its own columns and values,
 * however many or few — so the mapping declares none of them. One source node
 * holds the whole table and the feed speaks a fixed shape:
 *
 *   { "columns": ["a", "b"], "values": [[1, 2], [3, 4]] }
 *
 * with a column optionally an object carrying `label` plus `type` / `align` /
 * `width`.
 */
class TableMakerFieldTest extends Unit
{
    // -- schema ---------------------------------------------------------------

    public function testTheRowIsOneSourceNodeAndNoDefault(): void
    {
        $regions = (new TableMakerField())->schema($this->fakeField())->toArray();

        // A default is one literal value; what this takes is a whole table, and
        // no text box expresses one.
        $this->assertSame(['source', 'extra'], array_keys($regions));
        $this->assertSame(SchemaBuilder::SELECT, $regions['source'][0]['type']);
    }

    public function testTheFormatContractIsOnTheRow(): void
    {
        // A feed shipping the wrong shape is the only way this row can fail, and
        // there is nowhere else for the operator to learn what it wants.
        $note = (new TableMakerField())->schema($this->fakeField())->toArray()['extra'][0];

        $this->assertSame(SchemaBuilder::NOTE, $note['type']);
        $this->assertStringContainsString('columns', $note['text']);
        $this->assertStringContainsString('values', $note['text']);
        $this->assertStringContainsString('singleline', $note['text']);
    }

    public function testDropdownColumnsAreNotAccepted(): void
    {
        // A dropdown cell must be one of a closed set of options the feed has no
        // way to declare, so accepting one would store what the CP can't render.
        $this->assertArrayNotHasKey('select', TableMakerField::columnTypes());
        $this->assertArrayNotHasKey('heading', TableMakerField::columnTypes());
    }

    // -- parse ----------------------------------------------------------------

    public function testBareStringColumnsAreTheirLabels(): void
    {
        $parsed = $this->parse(['columns' => ['this', 'that'], 'values' => [[1, 2]]]);

        $this->assertSame([
            ['heading' => 'this', 'width' => '', 'align' => '', 'type' => 'singleline'],
            ['heading' => 'that', 'width' => '', 'align' => '', 'type' => 'singleline'],
        ], $parsed['columns']);
        // A bare-string column is single-line TEXT, so its cells store as text —
        // a feed shipping numbers into one gets them back as strings. A `number`
        // column keeps them numeric (below).
        $this->assertSame([['1', '2']], $parsed['rows']);
    }

    public function testANumberColumnKeepsItsCellsNumeric(): void
    {
        $parsed = $this->parse([
            'columns' => [['label' => 'n', 'type' => 'number'], 'text'],
            'values'  => [[1, 2]],
        ]);

        $this->assertSame([[1, '2']], $parsed['rows']);
    }

    public function testAColumnObjectCarriesItsOwnPresentation(): void
    {
        // A width may arrive as a number; Table Maker stores a string.
        $parsed = $this->parse([
            'columns' => [
                ['label' => 'this', 'width' => 50],
                ['label' => 'that', 'width' => 25, 'type' => 'number'],
                ['label' => 'other', 'width' => 25, 'align' => 'right'],
            ],
            'values' => [],
        ]);

        $this->assertSame([
            ['heading' => 'this', 'width' => '50', 'align' => '', 'type' => 'singleline'],
            ['heading' => 'that', 'width' => '25', 'align' => '', 'type' => 'number'],
            ['heading' => 'other', 'width' => '25', 'align' => 'right', 'type' => 'singleline'],
        ], $parsed['columns']);
    }

    public function testRowsArePositionalAndRaggedOnesAreHonoured(): void
    {
        // The user's own example: an explicit null IS an empty cell, and a short
        // row leaves the rest empty rather than shifting anything.
        $parsed = $this->parse([
            'columns' => ['this', 'that', 'other'],
            'values'  => [[1, 2, 3], [4, 5, 6], [null, 7, 8], ['pizza', 'sausage']],
        ]);

        $this->assertSame([
            ['1', '2', '3'],
            ['4', '5', '6'],
            [null, '7', '8'],
            ['pizza', 'sausage', null],
        ], $parsed['rows']);
    }

    public function testACellPastTheLastColumnIsDropped(): void
    {
        // Table Maker reads a cell as $row[$i] against $columns[$i], so a cell
        // with no column has nowhere to be stored.
        $parsed = $this->parse(['columns' => ['a', 'b'], 'values' => [[1, 2, 3, 4]]]);

        $this->assertSame([['1', '2']], $parsed['rows']);
    }

    public function testACellIsCoercedByItsColumnType(): void
    {
        $parsed = $this->parse([
            'columns' => [['label' => 'Flag', 'type' => 'lightswitch'], ['label' => 'Text']],
            'values'  => [['yes', '  padded  ']],
        ]);

        $this->assertSame([[true, 'padded']], $parsed['rows']);
    }

    public function testAnUnusableColumnTypeFallsBackToText(): void
    {
        $parsed = $this->parse([
            'columns' => [['label' => 'a', 'type' => 'select'], ['label' => 'b', 'type' => 'nonsense']],
            'values'  => [],
        ]);

        $this->assertSame(['singleline', 'singleline'], array_column($parsed['columns'], 'type'));
    }

    public function testAnUnlabelledColumnIsDropped(): void
    {
        // It would still occupy a position every row counts against, so keeping
        // it would silently widen the table with a blank heading.
        $parsed = $this->parse([
            'columns' => ['a', ['width' => 20], '', 'b'],
            'values'  => [[1, 2, 3, 4]],
        ]);

        $this->assertSame(['a', 'b'], array_column($parsed['columns'], 'heading'));
        $this->assertSame([['1', '2']], $parsed['rows']);
    }

    public function testATableWithNoColumnsClearsTheField(): void
    {
        // addressed() was true, so the feed is authoritative — and values with
        // nothing to hang on aren't a table.
        foreach ([['values' => [[1, 2]]], ['columns' => [], 'values' => [[1]]], 'nonsense', null] as $value) {
            $this->assertSame(
                ['columns' => [], 'rows' => []],
                $this->parse($value),
                'Unexpected parse for ' . json_encode($value),
            );
        }
    }

    // -- change detection -----------------------------------------------------

    public function testTheRenderedPreviewIsNeverCompared(): void
    {
        // Table Maker rebuilds `table` from the other two on every read, so
        // comparing it would report a change on every single sync.
        $stored = [
            'columns' => [['heading' => 'a', 'width' => '', 'align' => '', 'type' => 'singleline']],
            'rows'    => [['x']],
        ];

        $this->assertFalse($this->strategy()->exposedValueDiffers(
            $this->context(new RemoteItem([])),
            $stored + ['table' => '<table><tr><td>x</td></tr></table>'],
            $stored,
        ));
    }

    public function testARenamedHeadingIsAChange(): void
    {
        // The columns are part of the value here — the feed owns them.
        $rows = [['x']];
        $context = $this->context(new RemoteItem([]));

        $this->assertTrue($this->strategy()->exposedValueDiffers(
            $context,
            ['columns' => [['heading' => 'a', 'type' => 'singleline']], 'rows' => $rows],
            ['columns' => [['heading' => 'b', 'type' => 'singleline']], 'rows' => $rows],
        ));
    }

    public function testAStoredFlagMatchesTheFeedsSpellingOfIt(): void
    {
        // A CP round-trip stores a real bool where the feed carried "yes".
        $columns = [['heading' => 'Flag', 'width' => '', 'align' => '', 'type' => 'lightswitch']];
        $context = $this->context(new RemoteItem([]));

        $this->assertFalse($this->strategy()->exposedValueDiffers(
            $context,
            ['columns' => $columns, 'rows' => [[true]]],
            ['columns' => $columns, 'rows' => [['yes']]],
        ));
    }

    public function testAClearedFieldDiffersFromATableAndMatchesAClear(): void
    {
        $context = $this->context(new RemoteItem([]));
        $table = ['columns' => [['heading' => 'a', 'type' => 'singleline']], 'rows' => [['x']]];

        $this->assertTrue($this->strategy()->exposedValueDiffers($context, null, $table));
        $this->assertFalse($this->strategy()->exposedValueDiffers($context, null, null));
    }

    // -- helpers --------------------------------------------------------------

    protected function strategy(): TableMakerField
    {
        return new class() extends TableMakerField {
            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }
        };
    }

    /** Parse the given feed value, mapped from the item's `table` node. */
    protected function parse(mixed $value): mixed
    {
        return (new TableMakerField())->parse($this->context(new RemoteItem(['table' => $value])));
    }

    protected function context(RemoteItem $item): FieldContext
    {
        return new FieldContext(
            craftField: $this->fakeField(),
            handle: 'table',
            mapping: FieldMapping::fromConfig('table', ['node' => 'table']),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }

    /** The real class isn't installed here, and the strategy reads nothing off it. */
    protected function fakeField(): PlainText
    {
        return $this->createMock(PlainText::class);
    }
}
