<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\MappingSlots;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * {@see MappingSlots} — which stored slots a mapping row's declared regions can
 * write, and so which the save-time prune
 * ({@see \GlueAgency\Influx\services\LinksService::pruneMappings()}) strips.
 *
 * The region → slot and container → channel tables are pinned literally here
 * because they're the PHP half of a rule the SPA implements too
 * (`builder/lib/slots.js`, specced in `builder/lib/__tests__/slots.test.js`);
 * these assertions are what makes a one-sided change fail.
 */
class MappingSlotsTest extends Unit
{
    public function testTheRegionSlotTableMatchesTheSpa(): void
    {
        $this->assertSame(['source' => 'node', 'default' => 'default'], MappingSlots::REGION_SLOT);
    }

    public function testTheContainerChannelTableMatchesTheSpa(): void
    {
        $this->assertSame([
            'subFields'        => ['fields'],
            'matrixFields'     => ['blocks'],
            'elementSubFields' => ['fields', 'nativeFields'],
        ], MappingSlots::CONTAINER_CHANNELS);
    }

    public function testKnownSlotsCoverTheWholeStoredVocabulary(): void
    {
        // Exactly what FieldMapping::fromConfig() reads. A slot missing here would
        // never be pruned however stale it got.
        $this->assertSame(
            ['node', 'useDefault', 'default', 'options', 'fields', 'nativeFields', 'blocks'],
            MappingSlots::KNOWN_SLOTS,
        );
    }

    public function testTheDefaultRowBindsANodeItsUseDefaultFlagAndADefault(): void
    {
        $slots = MappingSlots::slots($this->regions(['source' => true, 'default' => true]));

        $this->assertSame(['node', 'useDefault', 'default'], $slots);
    }

    public function testAnExtrasLeafBindsTheOptionsSlotAndItsOwnKey(): void
    {
        $regions = $this->regions([
            'source' => true,
            'extra'  => fn(MappingSchemaBuilder $b)  => $b->matchBy(['options' => []])->dateFormat(['options' => []]),
        ]);

        $this->assertContains('options', MappingSlots::slots($regions));
        $this->assertSame(['match', 'format'], MappingSlots::optionKeys($regions));
    }

    public function testEachContainerBindsItsOwnChannels(): void
    {
        $this->assertSame(
            ['fields'],
            MappingSlots::slots($this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->subFields()])),
        );
        $this->assertSame(
            ['blocks'],
            MappingSlots::slots($this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->matrixFields()])),
        );
        $this->assertSame(
            ['fields', 'nativeFields'],
            MappingSlots::slots($this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->elementSubFields()])),
        );
    }

    public function testAContainerContributesNoOptionKey(): void
    {
        $regions = $this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->matrixFields()]);

        $this->assertSame([], MappingSlots::optionKeys($regions));
    }

    public function testANoteBindsNothing(): void
    {
        // The Preparse shape: a source region that renders copy and no control, so
        // a stored `node` has nothing to edit it and must not survive.
        $regions = $this->regions([
            'source'  => fn(MappingSchemaBuilder $b)  => $b->note(['text' => 'Computed from a template.']),
            'default' => false,
        ]);

        $this->assertSame([], MappingSlots::slots($regions));

        // Nothing is writable, so nothing survives — and an emptied mapping is
        // dropped from the link by LinksService::pruneMappingSlots().
        $this->assertSame([], MappingSlots::prune(['node' => 'coords', 'options' => ['mode' => 'url']], $regions));
    }

    public function testASubFieldsOnlyRowLosesItsSourceDefaultAndOptions(): void
    {
        // The reported case: a field that rendered one node gains sub-fields when a
        // custom-field integration is hooked, and the old slots have no cell left.
        $regions = $this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->subFields()]);

        $pruned = MappingSlots::prune([
            'node'    => 'coordinates',
            'default' => '',
            'options' => ['mode' => 'url'],
            'fields'  => ['lat' => ['node' => 'lat'], 'lng' => ['node' => 'lng']],
        ], $regions);

        $this->assertSame(['fields' => ['lat' => ['node' => 'lat'], 'lng' => ['node' => 'lng']]], $pruned);
    }

    public function testTheReverseDirectionDropsAStaleChannel(): void
    {
        // Integration unhooked: the row is a plain node + default again, so the
        // sub-field mappings it grew are the stale side now.
        $regions = $this->regions(['source' => true, 'default' => true]);

        $pruned = MappingSlots::prune([
            'node'   => 'coordinates',
            'fields' => ['lat' => ['node' => 'lat']],
            'blocks' => ['text' => []],
        ], $regions);

        $this->assertSame(['node' => 'coordinates'], $pruned);
    }

    public function testOptionsArePrunedKeyByKey(): void
    {
        $regions = $this->regions([
            'source' => true,
            'extra'  => fn(MappingSchemaBuilder $b)  => $b->matchBy(['options' => []]),
        ]);

        $pruned = MappingSlots::prune([
            'node'    => 'ref',
            'options' => ['match' => 'title', 'create' => true, 'mode' => 'url'],
        ], $regions);

        $this->assertSame(['node' => 'ref', 'options' => ['match' => 'title']], $pruned);
    }

    public function testAnEmptiedOptionsSlotIsDroppedEntirely(): void
    {
        $regions = $this->regions(['source' => true]);

        $this->assertSame(
            ['node' => 'ref'],
            MappingSlots::prune(['node' => 'ref', 'options' => ['create' => true]], $regions),
        );
    }

    public function testAnUnknownSlotSurvives(): void
    {
        // Same restraint as the processing prune: a key the vocabulary can't name
        // is not one this can judge.
        $regions = $this->regions(['source' => true]);

        $this->assertSame(
            ['node' => 'ref', 'legacyChannel' => ['x' => 1]],
            MappingSlots::prune(['node' => 'ref', 'legacyChannel' => ['x' => 1]], $regions),
        );
    }

    public function testEmptyRegionsLeaveTheMappingAlone(): void
    {
        // A descriptor that reported no row means "can't know", not "binds nothing"
        // — pruning against it would empty every mapping on the link.
        $mapping = ['node' => 'coordinates', 'options' => ['mode' => 'url'], 'fields' => ['lat' => []]];

        $this->assertSame($mapping, MappingSlots::prune($mapping, []));
    }

    public function testUnrecognizableRegionsLeaveTheMappingAlone(): void
    {
        // Non-empty but from no vocabulary this knows — a descriptor built by
        // something else, or a shape that predates the regions rework. Same
        // "can't know" answer as an empty set, and for the same reason: reading it
        // as "binds nothing" would delete every mapping on the link.
        $mapping = ['node' => 'coordinates', 'options' => ['mode' => 'url']];

        $this->assertSame($mapping, MappingSlots::prune($mapping, ['schema' => []]));
        $this->assertSame($mapping, MappingSlots::prune($mapping, ['cells' => [['type' => 'text']]]));
    }

    public function testPruningIsIdempotent(): void
    {
        $regions = $this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->subFields()]);
        $mapping = ['node' => 'coordinates', 'fields' => ['lat' => ['node' => 'lat']]];

        $once = MappingSlots::prune($mapping, $regions);
        $twice = MappingSlots::prune($once, $regions);

        $this->assertSame($once, $twice);
    }

    public function testANoteMixedWithARealControlStillBindsTheControl(): void
    {
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b
                ->note(['text' => 'Heads up.'])
                ->matchBy(['options' => []]),
        ]);

        $this->assertSame(['options'], MappingSlots::slots($regions));
        $this->assertSame(['match'], MappingSlots::optionKeys($regions));
    }

    public function testTheNoteTypeIsTheOneDisplayOnlyNode(): void
    {
        // If a second non-binding node type is ever added, MappingSlots has to
        // learn about it — this pins the assumption that there's only one today.
        $this->assertSame('note', SchemaBuilder::NOTE);
    }

    /**
     * A resolved regions array, exactly as {@see \GlueAgency\Influx\schema\MappableField::$mapping}
     * carries it — built through the real builder so the specs can't drift from
     * the shape a strategy actually produces.
     *
     * @param array<string, bool|callable> $regions
     * @return array<string, list<array>>
     */
    protected function regions(array $regions): array
    {
        return MappingSchemaBuilder::make()->mapping($regions)->toArray();
    }
}
