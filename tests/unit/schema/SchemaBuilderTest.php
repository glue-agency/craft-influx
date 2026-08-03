<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
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
        $schema = SchemaBuilder::make()
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
        $schema = SchemaBuilder::make()
            ->node('colorPicker', ['type' => 'text', 'handle' => 'accent'])
            ->toArray();

        $this->assertSame('colorPicker', $schema[0]['type']);
    }

    public function testShorthandDefaultsGiveWayToTheCallersConfig(): void
    {
        $schema = SchemaBuilder::make()
            ->matchBy(['default' => 'slug', 'label' => 'Look up by', 'options' => []])
            ->toArray();

        $this->assertSame('match', $schema[0]['handle'], 'The handle still comes from the shorthand.');
        $this->assertSame('slug', $schema[0]['default']);
        $this->assertSame('Look up by', $schema[0]['label']);
    }

    public function testSubFieldsDefaultsToTheFlatFieldsChannel(): void
    {
        $schema = SchemaBuilder::make()
            ->subFields(['label' => 'Columns', 'subFields' => [['type' => 'text', 'handle' => 'col1']]])
            ->toArray();

        $this->assertSame(SchemaBuilder::SUB_FIELDS, $schema[0]['type']);
        $this->assertSame('fields', $schema[0]['handle']);
        $this->assertSame('Columns', $schema[0]['label']);
    }

    public function testSubFieldsHandleIsAShorthandDefaultTheCallerCanOverride(): void
    {
        $schema = SchemaBuilder::make()
            ->subFields(['handle' => 'columns', 'label' => 'Columns'])
            ->toArray();

        $this->assertSame('columns', $schema[0]['handle']);
    }

    public function testSubFieldsTypeCannotBeOverriddenByConfig(): void
    {
        $schema = SchemaBuilder::make()
            ->subFields(['type' => 'text'])
            ->toArray();

        $this->assertSame(SchemaBuilder::SUB_FIELDS, $schema[0]['type']);
    }

    public function testAFieldRowTakesTheEditorItsFieldAsksFor(): void
    {
        // The row's editor is the target field's business, so the descriptor its
        // strategy returns is folded straight into the node — a relation asks
        // for a picker, an option field for a select, everything else reads as
        // text.
        $schema = SchemaBuilder::make()
            ->fieldRow(['type' => SchemaBuilder::ELEMENT, 'elementType' => 'craft\\elements\\Entry'], ['handle' => 'campus', 'label' => 'Campus'])
            ->fieldRow(['type' => SchemaBuilder::SELECT, 'options' => ['l' => 'Large', 's' => 'Small']], ['handle' => 'size', 'label' => 'Size'])
            ->fieldRow(['type' => 'somethingElse'], ['handle' => 'blurb', 'label' => 'Blurb'])
            ->fieldRow(null, ['handle' => 'note', 'label' => 'Note'])
            ->toArray();

        $this->assertSame([SchemaBuilder::ELEMENT, SchemaBuilder::SELECT, SchemaBuilder::TEXT, SchemaBuilder::TEXT], array_column($schema, 'type'));
        $this->assertSame('craft\\elements\\Entry', $schema[0]['elementType']);
        $this->assertSame(['campus', 'size', 'blurb', 'note'], array_column($schema, 'handle'));
    }

    public function testAFieldRowsSelectOptionsLeadWithABlankChoice(): void
    {
        // Without it a picked default can't be cleared again — the same
        // convention a table column's select and the top-level default follow.
        $schema = SchemaBuilder::make()
            ->fieldRow(['type' => SchemaBuilder::SELECT, 'options' => ['l' => 'Large']], ['handle' => 'size'])
            ->toArray();

        $this->assertSame(
            [['value' => '', 'label' => '—'], ['value' => 'l', 'label' => 'Large']],
            $schema[0]['options'],
        );
    }
}
