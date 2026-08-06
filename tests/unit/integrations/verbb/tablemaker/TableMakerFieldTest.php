<?php

namespace GlueAgency\Influx\Tests\unit\integrations\verbb\tablemaker;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\PlainText;
use GlueAgency\Influx\integrations\verbb\tablemaker\TableMakerField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Table Maker strategy.
 *
 * The whole reason this isn't {@see \GlueAgency\Influx\fields\Table}: a Table
 * Maker field's columns are per-entry CONTENT, so they come off the MAPPING and
 * are written to the field on every sync alongside the rows. The Craft field
 * itself is stubbed as any old field — the strategy reads only two optional
 * editor toggles off it, and the plugin isn't installed here.
 */
class TableMakerFieldTest extends Unit
{
    // -- schema ---------------------------------------------------------------

    public function testTheRowIsOneColumnCardAndNoCellsOfItsOwn(): void
    {
        $regions = (new TableMakerField())->schema($this->fakeField())->toArray();

        // Neither cell: the value comes entirely from the columns below, the
        // same declaration a Table or Matrix row makes.
        $this->assertSame(['extra'], array_keys($regions));
        $this->assertCount(1, $regions['extra']);
        $this->assertSame('tableMakerColumns', $regions['extra'][0]['type']);
        $this->assertSame('columns', $regions['extra'][0]['handle']);
    }

    public function testTheFieldsEditorTogglesRideAlong(): void
    {
        // A site that hides widths from its editors doesn't get to set them in
        // the mapping either.
        $node = $this->extras($this->fakeField(enableWidth: false, enableAlign: true));

        $this->assertFalse($node['enableWidth']);
        $this->assertTrue($node['enableAlign']);
    }

    public function testAFieldMissingThoseSettingsDefaultsThemOn(): void
    {
        // The plugin may be absent or older, and an unknown property on a Yii
        // component throws rather than coalescing — so this must not reach for
        // one that isn't declared.
        $node = $this->extras($this->createMock(PlainText::class));

        $this->assertTrue($node['enableWidth']);
        $this->assertTrue($node['enableAlign']);
    }

    public function testDropdownColumnsAreNotOffered(): void
    {
        // A select column carries its own option list, which nothing here can
        // declare — writing an arbitrary feed string into a closed-set cell
        // would store something the CP can't render back.
        $types = TableMakerField::columnTypes();

        $this->assertArrayNotHasKey('select', $types);
        $this->assertArrayNotHasKey('heading', $types);
        $this->assertArrayHasKey('singleline', $types);
        $this->assertArrayHasKey('lightswitch', $types);
        $this->assertSame($types, $this->extras($this->fakeField())['columnTypes']);
    }

    // -- addressed ------------------------------------------------------------

    public function testDeclaredColumnsAloneDoNotAddressTheField(): void
    {
        // Otherwise a feed that carries none of the mapped nodes would blank a
        // populated table down to bare headings.
        $context = $this->context(
            new RemoteItem(['other' => 'x']),
            ['c1' => ['node' => 'consultations.day']],
        );

        $this->assertFalse((new TableMakerField())->addressed($context));
    }

    public function testAnyAddressedColumnAddressesTheField(): void
    {
        $context = $this->context(
            new RemoteItem(['consultations' => [['day' => 'Monday']]]),
            ['c1' => ['node' => 'consultations.day']],
        );

        $this->assertTrue((new TableMakerField())->addressed($context));
    }

    // -- parse ----------------------------------------------------------------

    public function testColumnsAndRowsAreWrittenTogetherPositionally(): void
    {
        $context = $this->context(
            new RemoteItem(['consultations' => [
                ['day' => 'Monday', 'from' => '09:00'],
                ['day' => 'Tuesday', 'from' => '13:00'],
            ]]),
            [
                'c1' => ['node' => 'consultations.day'],
                'c2' => ['node' => 'consultations.from'],
            ],
        );

        $this->assertSame([
            // Influx's own `id` is stripped: the stored shape is a bare
            // positional list with no identity of its own.
            'columns' => [
                ['heading' => 'Day', 'width' => '', 'align' => '', 'type' => 'singleline'],
                ['heading' => 'From', 'width' => '100', 'align' => 'left', 'type' => 'time'],
            ],
            'rows' => [
                ['Monday', '09:00'],
                ['Tuesday', '13:00'],
            ],
        ], (new TableMakerField())->parse($context));
    }

    public function testAnUnmappedColumnStillGetsItsCellOnEveryRow(): void
    {
        // Table Maker reads a cell as $row[$i] against $columns[$i], so a row is
        // a fixed-width list — a hole would shift every later column's value
        // into the wrong heading.
        $context = $this->context(
            new RemoteItem(['consultations' => [['day' => 'Monday'], ['day' => 'Tuesday']]]),
            ['c1' => ['node' => 'consultations.day']],
        );

        $this->assertSame([
            ['Monday', null],
            ['Tuesday', null],
        ], (new TableMakerField())->parse($context)['rows']);
    }

    public function testTheColumnsSurviveAFeedThatCarriesNoRows(): void
    {
        // addressed() was true, so the feed is authoritative: headings with no
        // rows IS "the feed carries none of these".
        $context = $this->context(
            new RemoteItem(['consultations' => []]),
            ['c1' => ['node' => 'consultations.day']],
        );
        $parsed = (new TableMakerField())->parse($context);

        $this->assertSame([], $parsed['rows']);
        $this->assertCount(2, $parsed['columns']);
    }

    public function testACellIsCoercedByItsColumnType(): void
    {
        $context = $this->context(
            new RemoteItem(['rows' => [['flag' => 'yes', 'text' => '  padded  ']]]),
            ['c1' => ['node' => 'rows.flag'], 'c2' => ['node' => 'rows.text']],
            [
                ['id' => 'c1', 'heading' => 'Flag', 'type' => 'lightswitch'],
                ['id' => 'c2', 'heading' => 'Text', 'type' => 'singleline'],
            ],
        );

        $this->assertSame([[true, 'padded']], (new TableMakerField())->parse($context)['rows']);
    }

    public function testAColumnWithoutAnIdIsDropped(): void
    {
        // The id is the only thing tying a column to its sub-mapping, and stored
        // config is operator-authored JSON.
        $context = $this->context(
            new RemoteItem(['consultations' => [['day' => 'Monday']]]),
            ['c1' => ['node' => 'consultations.day']],
            [
                ['id' => 'c1', 'heading' => 'Day', 'type' => 'singleline'],
                ['heading' => 'Orphan', 'type' => 'singleline'],
            ],
        );

        $this->assertSame([['heading' => 'Day', 'width' => '', 'align' => '', 'type' => 'singleline']],
            (new TableMakerField())->parse($context)['columns']);
    }

    public function testAnUnknownColumnTypeFallsBackToText(): void
    {
        $context = $this->context(
            new RemoteItem(['consultations' => [['day' => 'Monday']]]),
            ['c1' => ['node' => 'consultations.day']],
            [['id' => 'c1', 'heading' => 'Day', 'type' => 'nonsense']],
        );

        $this->assertSame('singleline', (new TableMakerField())->parse($context)['columns'][0]['type']);
    }

    // -- change detection -----------------------------------------------------

    public function testTheRenderedPreviewIsNeverCompared(): void
    {
        // Table Maker rebuilds `table` from the other two keys on every read, so
        // comparing it would report a change on every single sync.
        $context = $this->context(new RemoteItem([]), ['c1' => ['node' => 'consultations.day']]);
        $incoming = [
            'columns' => [
                ['heading' => 'Day', 'width' => '', 'align' => '', 'type' => 'singleline'],
                ['heading' => 'From', 'width' => '100', 'align' => 'left', 'type' => 'time'],
            ],
            'rows' => [['Monday', '09:00']],
        ];
        $current = $incoming + ['table' => '<table><tr><td>Monday</td></tr></table>'];

        $this->assertFalse($this->strategy()->exposedValueDiffers($context, $current, $incoming));
    }

    public function testAnEditedHeadingIsAChangeEvenWithIdenticalCells(): void
    {
        // The columns are part of the value here, unlike a Craft Table's.
        $context = $this->context(new RemoteItem([]), ['c1' => ['node' => 'consultations.day']]);
        $rows = [['Monday', '09:00']];

        $current = [
            'columns' => [
                ['heading' => 'Weekday', 'width' => '', 'align' => '', 'type' => 'singleline'],
                ['heading' => 'From', 'width' => '100', 'align' => 'left', 'type' => 'time'],
            ],
            'rows' => $rows,
        ];
        $incoming = ['columns' => $this->declaredColumnsAsStored(), 'rows' => $rows];

        $this->assertTrue($this->strategy()->exposedValueDiffers($context, $current, $incoming));
    }

    public function testAClearedFieldDiffersFromRowsAndMatchesAClear(): void
    {
        $context = $this->context(new RemoteItem([]), ['c1' => ['node' => 'consultations.day']]);
        $rows = ['columns' => $this->declaredColumnsAsStored(), 'rows' => [['Monday', '09:00']]];

        $this->assertTrue($this->strategy()->exposedValueDiffers($context, null, $rows));
        $this->assertFalse($this->strategy()->exposedValueDiffers($context, null, null));
    }

    public function testATimeCellComparesByInstantNotSpelling(): void
    {
        // The stored side has been through Craft's normalize; the feed still
        // carries whatever it spelled.
        $context = $this->context(new RemoteItem([]), ['c2' => ['node' => 'consultations.from']]);
        $columns = $this->declaredColumnsAsStored();

        $this->assertFalse($this->strategy()->exposedValueDiffers(
            $context,
            ['columns' => $columns, 'rows' => [['Monday', '1970-01-01T09:00:00+00:00']]],
            ['columns' => $columns, 'rows' => [['Monday', '1970-01-01T09:00:00Z']]],
        ));
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

    /**
     * The `extra` region's single node.
     *
     * @return array<string, mixed>
     */
    protected function extras(CraftFieldInterface $field): array
    {
        return (new TableMakerField())->schema($field)->toArray()['extra'][0];
    }

    /**
     * A Craft field standing in for a Table Maker one — the two editor toggles
     * are all the strategy reads, and the real class isn't installed here.
     */
    protected function fakeField(bool $enableWidth = true, bool $enableAlign = true): CraftFieldInterface
    {
        return new class($enableWidth, $enableAlign) extends PlainText {
            public bool $enableWidthColumn = true;

            public bool $enableAlignmentColumn = true;

            public function __construct(bool $enableWidth, bool $enableAlign)
            {
                $this->enableWidthColumn = $enableWidth;
                $this->enableAlignmentColumn = $enableAlign;

                parent::__construct();
            }
        };
    }

    /** The recurring two-column fixture, as the strategy would store it. */
    protected function declaredColumnsAsStored(): array
    {
        return [
            ['heading' => 'Day', 'width' => '', 'align' => '', 'type' => 'singleline'],
            ['heading' => 'From', 'width' => '100', 'align' => 'left', 'type' => 'time'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $fields column id → sub-mapping
     * @param ?list<array<string, mixed>> $columns declared columns, defaulting to the fixture
     */
    protected function context(RemoteItem $item, array $fields, ?array $columns = null): FieldContext
    {
        return new FieldContext(
            craftField: $this->fakeField(),
            handle: 'consultations',
            mapping: FieldMapping::fromConfig('consultations', [
                'fields'  => $fields,
                'options' => ['columns' => $columns ?? [
                    ['id' => 'c1', 'heading' => 'Day', 'type' => 'singleline'],
                    ['id' => 'c2', 'heading' => 'From', 'type' => 'time', 'width' => '100', 'align' => 'left'],
                ]],
            ]),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
