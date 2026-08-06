<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * The two builder conventions a third party relies on: `node()` passes a type
 * the builder doesn't ship straight through (the SPA renders an unrecognised
 * type as a labeled text input, so it degrades instead of vanishing), and every
 * shorthand folds its defaults UNDER the caller's config, so `$config` wins on
 * every key except `type`.
 */
class SchemaBuilderTest extends Unit
{
    public function testNodePassesAThirdPartyTypeThrough(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->node('colorPicker', ['handle' => 'accent', 'label' => 'Accent', 'default' => '#f00'])
            ->toArray();

        $this->assertSame([[
            'type'    => 'colorPicker',
            'handle'  => 'accent',
            'label'   => 'Accent',
            'default' => '#f00',
        ]], $schema);
    }

    public function testNodeTypeCannotBeOverriddenByConfig(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->node('colorPicker', ['type' => 'text', 'handle' => 'accent'])
            ->toArray();

        $this->assertSame('colorPicker', $schema[0]['type']);
    }

    public function testShorthandDefaultsGiveWayToTheCallersConfig(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->matchBy(['default' => 'slug', 'label' => 'Look up by', 'options' => []])
            ->toArray();

        $this->assertSame('match', $schema[0]['handle'], 'The handle still comes from the shorthand.');
        $this->assertSame('slug', $schema[0]['default']);
        $this->assertSame('Look up by', $schema[0]['label']);
    }

    public function testSubFieldsDefaultsToTheFlatFieldsChannel(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->subFields(['label' => 'Columns', 'subFields' => [['type' => 'text', 'handle' => 'col1']]])
            ->toArray();

        $this->assertSame(MappingSchemaBuilder::SUB_FIELDS, $schema[0]['type']);
        $this->assertSame('fields', $schema[0]['handle']);
        $this->assertSame('Columns', $schema[0]['label']);
    }

    public function testSubFieldsHandleIsAShorthandDefaultTheCallerCanOverride(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->subFields(['handle' => 'columns', 'label' => 'Columns'])
            ->toArray();

        $this->assertSame('columns', $schema[0]['handle']);
    }

    public function testSubFieldsTypeCannotBeOverriddenByConfig(): void
    {
        $schema = MappingSchemaBuilder::make()
            ->subFields(['type' => 'text'])
            ->toArray();

        $this->assertSame(MappingSchemaBuilder::SUB_FIELDS, $schema[0]['type']);
    }

    public function testAFieldRowRendersTheControlItsFieldsOwnCellDoes(): void
    {
        // A sub-field row's control is the target field's business, so its default
        // cell's NODE is the row — a relation's picker, an option field's select
        // over its own options, everything else a text box. The row's identity is
        // laid over it, and a type it doesn't declare falls back to text.
        $schema = MappingSchemaBuilder::make()
            ->fieldRow(['default' => ['type' => SchemaBuilder::ELEMENT, 'elementType' => 'craft\\elements\\Entry']], ['handle' => 'campus', 'label' => 'Campus'])
            ->fieldRow(['default' => ['type' => 'somethingElse']], ['handle' => 'blurb', 'label' => 'Blurb'])
            ->fieldRow(['default' => []], ['handle' => 'hint', 'label' => 'Hint'])
            ->fieldRow(['default' => ['type' => SchemaBuilder::TEXT]], ['handle' => 'note', 'label' => 'Note'])
            ->toArray();

        $this->assertSame(
            [SchemaBuilder::ELEMENT, 'somethingElse', SchemaBuilder::TEXT, SchemaBuilder::TEXT],
            array_column($schema, 'type'),
        );
        $this->assertSame('craft\\elements\\Entry', $schema[0]['elementType']);
        $this->assertSame(['campus', 'blurb', 'hint', 'note'], array_column($schema, 'handle'));
    }

    public function testAFieldRowCarriesEverythingTheCellDeclared(): void
    {
        // The cell arrives whole rather than reduced to a type: an option field's
        // sentinel and its search box come along, so the row behaves the way that
        // field's own default cell does.
        $cell = [
            'type'            => SchemaBuilder::SELECT,
            'options'         => [['value' => 'l', 'label' => 'Large']],
            'sentinelOptions' => [['value' => '', 'label' => '— no default —']],
            'lazy'            => true,
        ];

        $schema = MappingSchemaBuilder::make()->fieldRow(['default' => $cell], ['handle' => 'size'])->toArray();

        $this->assertSame($cell['options'], $schema[0]['options']);
        $this->assertSame($cell['sentinelOptions'], $schema[0]['sentinelOptions']);
        $this->assertTrue($schema[0]['lazy']);
    }

    public function testAFieldRowsOwnIdentityWinsOverTheCells(): void
    {
        // The cell may carry a handle of its own (a native's does); the ROW's is
        // what addresses the stored sub-mapping.
        $schema = MappingSchemaBuilder::make()
            ->fieldRow(['default' => ['type' => SchemaBuilder::TEXT, 'handle' => 'title']], ['handle' => 'col1', 'label' => 'Label'])
            ->toArray();

        $this->assertSame('col1', $schema[0]['handle']);
    }

    public function testAChildWithNoCellOfItsOwnSaysSo(): void
    {
        // A nested Table, Link or Table Maker: its value comes entirely from its
        // own extras, exactly as its top-level row declares by dropping both
        // regions. Without `cells => false` the row fell to the text fallback, so
        // the card offered a node select and a text box writing into slots no sync
        // reads — while the real configuration sat behind the chevron beside them.
        $schema = MappingSchemaBuilder::make()
            ->fieldRow(
                ['default' => null, 'extra' => [['type' => SchemaBuilder::LIGHTSWITCH, 'handle' => 'flag']]],
                ['handle' => 'table', 'label' => 'Tabel'],
            )
            ->toArray();

        $this->assertFalse($schema[0]['cells']);
        $this->assertSame([['type' => SchemaBuilder::LIGHTSWITCH, 'handle' => 'flag']], $schema[0]['extra']);
    }

    public function testAChildWithACellIsNotMarkedCellLess(): void
    {
        // Guards the flag from becoming unconditional, which would blank every
        // sub-field row in the builder.
        $schema = MappingSchemaBuilder::make()
            ->fieldRow(['default' => ['type' => SchemaBuilder::TEXT], 'extra' => []], ['handle' => 'col1'])
            ->toArray();

        $this->assertArrayNotHasKey('cells', $schema[0]);
    }

    /**
     * A native declares ONE node and that node IS its default cell, so the terse
     * form gets the standard source select, the declared control as its default,
     * and its `extras` as the extras region — without naming a region.
     */
    public function testANativesDeclaredNodeBecomesItsDefaultCell(): void
    {
        [$native] = MappingSchemaBuilder::make()
            ->group('Native', fn(MappingSchemaBuilder $g) => $g->select([
                'handle'  => 'enabled',
                'name'    => 'Enabled',
                'options' => ['true' => 'Enabled', 'false' => 'Disabled'],
                'extras'  => fn(MappingSchemaBuilder $b)  => $b->createWhenMissing(),
            ]))
            ->toArray();

        $regions = $native->toArray()['mapping'];

        $this->assertSame(['source', 'default', 'extra'], array_keys($regions));
        $this->assertSame(MappingSchemaBuilder::make()->sourceNode()->toArray(), $regions['source']);
        // The option MAP a descriptor speaks becomes the option LIST a node does,
        // through the same defaultSelect() preset a field strategy declares its own
        // default cell with — so a native's select can't drift from a custom
        // field's.
        $this->assertSame([[
            'type'    => SchemaBuilder::SELECT,
            'handle'  => 'enabled',
            'options' => [
                ['value' => 'true',  'label' => 'Enabled'],
                ['value' => 'false', 'label' => 'Disabled'],
            ],
            'searchable'        => true,
            'searchPlaceholder' => 'Search options…',
            'sentinelOptions'   => [['value' => '', 'label' => '— no default —']],
        ]], $regions['default']);
        $this->assertSame('create', $regions['extra'][0]['handle']);
    }

    /**
     * The one native that renders no cells — a user's group toggles, whose value IS
     * the extras. It says so by dropping the regions, the same way a Matrix does,
     * rather than through a flag of its own.
     */
    public function testANativeCanDropBothCells(): void
    {
        [$native] = MappingSchemaBuilder::make()
            ->group('Native', fn(MappingSchemaBuilder $g) => $g->text([
                'handle' => 'groups',
                'name'   => 'Groups',
                'cells'  => ['source' => false, 'default' => false],
                'extras' => fn(MappingSchemaBuilder $b) => $b->lightswitch(['handle' => 'editors']),
            ]))
            ->toArray();

        $this->assertSame(['extra'], array_keys($native->toArray()['mapping']));
    }
}
