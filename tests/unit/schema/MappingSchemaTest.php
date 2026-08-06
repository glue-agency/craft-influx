<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * Behaviour spec for a mapping row's three regions.
 *
 * The point of the type is that ABSENCE is the signal: a region nothing declares
 * is a cell the row doesn't render, which is what replaced the `subfieldsOnly` and
 * `unmappable` flags. Nothing to keep in sync, nothing to infer.
 */
class MappingSchemaTest extends Unit
{
    public function testADeclaredRegionCarriesItsNodes(): void
    {
        $schema = MappingSchemaBuilder::make()->mapping([
            'default' => fn(MappingSchemaBuilder $b) => $b->select(['options' => [['value' => 'a', 'label' => 'A']]]),
        ]);

        $this->assertSame(
            [['type' => SchemaBuilder::SELECT, 'options' => [['value' => 'a', 'label' => 'A']]]],
            $schema->toArray()['default'],
        );
    }

    /**
     * @dataProvider undeclared
     */
    public function testAnUndeclaredRegionIsAbsentEntirely(mixed $declaration): void
    {
        $schema = MappingSchemaBuilder::make()->mapping(['source' => true, 'default' => $declaration]);

        $this->assertArrayNotHasKey('default', $schema->toArray());
        $this->assertFalse($schema->has('default'));
        $this->assertTrue($schema->has('source'), 'A sibling region is unaffected.');
    }

    public static function undeclared(): iterable
    {
        yield 'false' => [false];
        yield 'null' => [null];
        // A callback that declares nothing is the same as declaring nothing.
        yield 'no-op' => [fn(MappingSchemaBuilder $b) => $b];
    }

    public function testAnOmittedRegionIsAbsentToo(): void
    {
        $schema = MappingSchemaBuilder::make()->mapping(['source' => true]);

        $this->assertSame(['source'], array_keys($schema->toArray()));
    }

    /**
     * `true` must resolve through the SAME path its callback would, or a field
     * wanting one tweak has to reproduce everything the preset gave it.
     */
    public function testTrueResolvesToThePresetTheCallbackWouldBuild(): void
    {
        $sugar = MappingSchemaBuilder::make()->mapping(['source' => true]);
        $spelt = MappingSchemaBuilder::make()->mapping(['source' => fn(MappingSchemaBuilder $b) => $b->sourceNode()]);

        $this->assertSame($spelt->toArray(), $sugar->toArray());
    }

    public function testTheDefaultRegionsPresetIsAPlainTextInput(): void
    {
        $schema = MappingSchemaBuilder::make()->mapping(['default' => true]);

        $this->assertSame([['type' => SchemaBuilder::TEXT]], $schema->toArray()['default']);
    }

    /**
     * There is no such thing as a generic extras block, so `true` there declares
     * nothing rather than guessing at one.
     */
    public function testExtraHasNoPreset(): void
    {
        $this->assertSame([], MappingSchemaBuilder::make()->mapping(['extra' => true])->toArray());
    }

    public function testRegionsComeOutInRenderOrder(): void
    {
        // Declared back to front; the row reads source, default, extra.
        $schema = MappingSchemaBuilder::make()->mapping([
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->matchBy(['options' => []]),
            'default' => true,
            'source'  => true,
        ]);

        $this->assertSame(['source', 'default', 'extra'], array_keys($schema->toArray()));
    }

    /**
     * The Matrix shape: no cells of its own, everything in the extras.
     */
    public function testAFieldWhoseValueDerivesFromSubMappingsDeclaresNoCells(): void
    {
        $schema = MappingSchemaBuilder::make()->mapping([
            'source'  => false,
            'default' => false,
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->subFields(['label' => 'Columns', 'subFields' => []]),
        ]);

        $this->assertSame(['extra'], array_keys($schema->toArray()));
    }

    /**
     * The Preparse shape, and what distinguishes it from the Matrix one above: it
     * HAS a source region, holding nothing but a note. No flag, and no
     * inline-vs-collapsible rule — a note renders wherever its region does.
     */
    public function testAnUnmappableFieldPutsItsNoteInTheSourceRegion(): void
    {
        $schema = MappingSchemaBuilder::make()->mapping([
            'source'  => fn(MappingSchemaBuilder $b)  => $b->note(['text' => 'Computed on every save.']),
            'default' => false,
        ]);

        $this->assertSame(['source'], array_keys($schema->toArray()));
        $this->assertSame(SchemaBuilder::NOTE, $schema->toArray()['source'][0]['type']);
    }

    /**
     * THE guard on the laziness, and it's about more than cost. A sub-field row
     * asks another field's strategy for its default cell, so building a whole row
     * to answer that would build ITS sub-field rows, each asking again — unbounded
     * on a cyclic relation graph.
     */
    public function testAskingForOneRegionBuildsOnlyThatRegion(): void
    {
        $built = [];
        $record = function(string $region) use (&$built): callable {
            return function(MappingSchemaBuilder $b) use ($region, &$built): void {
                $built[] = $region;
                $b->text();
            };
        };

        $schema = MappingSchemaBuilder::make()->mapping([
            'source'  => $record('source'),
            'default' => $record('default'),
            'extra'   => $record('extra'),
        ]);

        $this->assertSame([], $built, 'Declaring a row builds nothing.');

        $schema->region('default');
        $this->assertSame(['default'], $built);

        $schema->region('default');
        $this->assertSame(['default'], $built, 'A resolved region is remembered, not rebuilt.');

        $schema->toArray();
        $this->assertSame(['default', 'source', 'extra'], $built, 'The wire shape needs them all.');
    }
}
