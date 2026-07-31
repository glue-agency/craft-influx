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
}
