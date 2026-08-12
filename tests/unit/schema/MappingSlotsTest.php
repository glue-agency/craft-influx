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

    public function testTheBlockChannelTableMatchesTheSpa(): void
    {
        // What ONE block-type entry inside `blocks` holds — the two channels
        // MatrixFields.vue splits its rows back into.
        $this->assertSame(['fields', 'nativeFields'], MappingSlots::BLOCK_CHANNELS);
    }

    public function testTheChannelDefaultTableMatchesTheSpa(): void
    {
        // The `defaultChannel` argument at the SPA's two splitChannels() call
        // sites. The asymmetry is deliberate — see elementSubFields()'s docblock.
        $this->assertSame([
            'subFields'        => 'fields',
            'matrixFields'     => 'fields',
            'elementSubFields' => 'nativeFields',
        ], MappingSlots::CHANNEL_DEFAULT);
    }

    public function testAStaleSubFieldRowIsDroppedFromItsChannel(): void
    {
        // The child-level twin of the handle pass: the card renders rows from its
        // roster, so a row the roster dropped isn't even badged — just resolved.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields([
                'subFields' => SchemaBuilder::make()
                    ->text(['handle' => 'lat', 'label' => 'Lat'])
                    ->text(['handle' => 'lng', 'label' => 'Lng'])
                    ->toArray(),
            ]),
        ]);

        $pruned = MappingSlots::prune([
            'fields' => [
                'lat'    => ['node' => 'lat'],
                'lng'    => ['node' => 'lng'],
                'legacy' => ['node' => 'gone'],
            ],
        ], $regions);

        $this->assertSame(['fields' => ['lat' => ['node' => 'lat'], 'lng' => ['node' => 'lng']]], $pruned);
    }

    public function testAContainerWithoutARosterJudgesNoRow(): void
    {
        // "Didn't report a roster" is not "reported an empty roster" — the same
        // restraint the region guard applies one level up.
        $regions = $this->regions(['extra' => fn(MappingSchemaBuilder $b) => $b->subFields()]);
        $mapping = ['fields' => ['lat' => ['node' => 'lat'], 'legacy' => ['node' => 'gone']]];

        $this->assertSame($mapping, MappingSlots::prune($mapping, $regions));
    }

    public function testAnEmptyRosterEmptiesTheChannel(): void
    {
        // A card that renders and offers no rows: a stored row has no control.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields(['subFields' => []]),
        ]);

        $this->assertSame([], MappingSlots::prune(['fields' => ['lat' => ['node' => 'lat']]], $regions));
    }

    public function testAChildLosesTheSlotsItsOwnCellsDontRender(): void
    {
        // A sub-field whose strategy renders no source select — its stored node
        // and useDefault flag have nothing to edit or clear them.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields([
                'subFields' => MappingSchemaBuilder::make()
                    ->fieldRow(
                        ['source' => false, 'default' => ['type' => 'text'], 'extra' => []],
                        ['handle' => 'computed', 'label' => 'Computed'],
                    )
                    ->toArray(),
            ]),
        ]);

        $pruned = MappingSlots::prune([
            'fields' => ['computed' => ['node' => 'x', 'useDefault' => true, 'default' => 'fallback']],
        ], $regions);

        $this->assertSame(['fields' => ['computed' => ['default' => 'fallback']]], $pruned);
    }

    public function testAChildKeepsItsUseDefaultFlagUnderARenderedSelect(): void
    {
        // The sentinel synthesis: the child select always offers `__default__`, so
        // the flag lives or dies with the select rather than being named on its own.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields([
                'subFields' => SchemaBuilder::make()->text(['handle' => 'lat', 'label' => 'Lat'])->toArray(),
            ]),
        ]);

        $this->assertSame(
            ['fields' => ['lat' => ['useDefault' => true, 'default' => '50.8']]],
            MappingSlots::prune(['fields' => ['lat' => ['useDefault' => true, 'default' => '50.8']]], $regions),
        );
    }

    public function testAChildsOptionsArePrunedAgainstItsOwnExtras(): void
    {
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields([
                'subFields' => MappingSchemaBuilder::make()
                    ->fieldRow(
                        [
                            'source'  => true,
                            'default' => ['type' => 'text'],
                            'extra'   => MappingSchemaBuilder::make()->matchBy(['options' => []])->toArray(),
                        ],
                        ['handle' => 'ref', 'label' => 'Ref'],
                    )
                    ->toArray(),
            ]),
        ]);

        $pruned = MappingSlots::prune([
            'fields' => ['ref' => ['node' => 'ref', 'options' => ['match' => 'title', 'create' => true]]],
        ], $regions);

        $this->assertSame(['fields' => ['ref' => ['node' => 'ref', 'options' => ['match' => 'title']]]], $pruned);
    }

    public function testARowSurvivesInTheChannelItWasSavedIn(): void
    {
        // Union match, not same-channel: the card renders both channels as one
        // handle-keyed table and round-trips a row to where it came from, so this
        // row is still visible and editable. The next client write migrates it.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->elementSubFields([
                'subFields' => SchemaBuilder::make()->text(['handle' => 'alt', 'label' => 'Alt'])->toArray(),
            ]),
        ]);

        $this->assertSame(
            ['fields' => ['alt' => ['node' => 'alt']]],
            MappingSlots::prune(['fields' => ['alt' => ['node' => 'alt']]], $regions),
        );
    }

    public function testACollisionKeepsOnlyTheRosterChannel(): void
    {
        // Both channels holding one handle means a stale copy: the card draws one
        // row per handle (nativeFields winning), so the other is unreachable.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->elementSubFields([
                'subFields' => SchemaBuilder::make()->text(['handle' => 'alt', 'label' => 'Alt'])->toArray(),
            ]),
        ]);

        $pruned = MappingSlots::prune([
            'fields'       => ['alt' => ['node' => 'stale']],
            'nativeFields' => ['alt' => ['node' => 'alt']],
        ], $regions);

        $this->assertSame(['nativeFields' => ['alt' => ['node' => 'alt']]], $pruned);
    }

    public function testAnUnknownBlockTypeIsDroppedWithItsStaleRows(): void
    {
        $pruned = MappingSlots::prune([
            'node'   => 'items',
            'blocks' => [
                'hero' => ['fields' => ['heading' => ['node' => 'h'], 'legacy' => ['node' => 'g']]],
                'gone' => ['fields' => ['x' => ['node' => 'x']]],
            ],
        ], $this->matrixRegions('hero', ['heading']));

        $this->assertSame([
            'node'   => 'items',
            'blocks' => ['hero' => ['fields' => ['heading' => ['node' => 'h']]]],
        ], $pruned);
    }

    public function testABlockCardWithoutATypeLeavesTheTypeLevelAlone(): void
    {
        // An incomplete roster can't tell a stale type from one another card names.
        $regions = $this->regions([
            'source' => true,
            'extra'  => fn(MappingSchemaBuilder $b)  => $b->matrixFields([
                'label'     => 'Hero',
                'subFields' => SchemaBuilder::make()->text(['handle' => 'heading', 'label' => 'Heading'])->toArray(),
            ]),
        ]);
        $mapping = ['node' => 'items', 'blocks' => ['hero' => ['fields' => ['heading' => ['node' => 'h']]]]];

        $this->assertSame($mapping, MappingSlots::prune($mapping, $regions));
    }

    public function testAnEmptiedChannelCollapsesUpThroughTheTypeToTheBlocksSlot(): void
    {
        $pruned = MappingSlots::prune([
            'node'   => 'items',
            'blocks' => ['hero' => ['fields' => ['heading' => ['node' => 'h']]]],
        ], $this->matrixRegions('hero', []));

        $this->assertSame(['node' => 'items'], $pruned);
    }

    public function testUnknownKeysOnABlockTypeEntrySurvive(): void
    {
        $pruned = MappingSlots::prune([
            'node'   => 'items',
            'blocks' => ['hero' => ['fields' => ['legacy' => ['node' => 'g']], 'legacyKey' => 1]],
        ], $this->matrixRegions('hero', ['heading']));

        $this->assertSame(['node' => 'items', 'blocks' => ['hero' => ['legacyKey' => 1]]], $pruned);
    }

    public function testADepthThreeRowKeepsItsCellsAndLosesItsOptions(): void
    {
        // Pins the accepted depth bound: childRowFor() elides extras one card deep
        // and fieldRow() omits an empty `extra`, so at depth 3 "declares no extras"
        // and "extras were cut" are the same shape. Cells stay known at every
        // depth, so the node survives — only the options the builder can neither
        // render nor clear are stripped.
        $regions = $this->regions([
            'extra' => fn(MappingSchemaBuilder $b) => $b->subFields([
                'subFields' => MappingSchemaBuilder::make()
                    ->fieldRow(
                        [
                            'source'  => true,
                            'default' => ['type' => 'text'],
                            'extra'   => MappingSchemaBuilder::make()
                                ->elementSubFields([
                                    'subFields' => MappingSchemaBuilder::make()
                                        ->fieldRow(
                                            ['source' => true, 'default' => ['type' => 'text'], 'extra' => []],
                                            ['handle' => 'street', 'label' => 'Street'],
                                        )
                                        ->toArray(),
                                ])
                                ->toArray(),
                        ],
                        ['handle' => 'address', 'label' => 'Address'],
                    )
                    ->toArray(),
            ]),
        ]);

        $pruned = MappingSlots::prune([
            'fields' => [
                'address' => [
                    'node'         => 'addr',
                    'nativeFields' => ['street' => ['node' => 's', 'options' => ['mode' => 'x']]],
                ],
            ],
        ], $regions);

        $this->assertSame([
            'fields' => [
                'address' => [
                    'node'         => 'addr',
                    'nativeFields' => ['street' => ['node' => 's']],
                ],
            ],
        ], $pruned);
    }

    public function testNestedPruningIsIdempotent(): void
    {
        $regions = $this->matrixRegions('hero', ['heading']);
        $mapping = [
            'node'   => 'items',
            'blocks' => [
                'hero' => ['fields' => ['heading' => ['node' => 'h'], 'legacy' => ['node' => 'g']]],
                'gone' => ['fields' => ['x' => ['node' => 'x']]],
            ],
        ];

        $once = MappingSlots::prune($mapping, $regions);
        $twice = MappingSlots::prune($once, $regions);

        $this->assertSame($once, $twice);
    }

    /**
     * A Matrix field's regions: a source node plus one block-type card naming
     * `$blockType` and rostering `$handles`.
     *
     * @param list<string> $handles
     * @return array<string, list<array>>
     */
    protected function matrixRegions(string $blockType, array $handles): array
    {
        $subFields = SchemaBuilder::make();

        foreach ($handles as $handle) {
            $subFields->text(['handle' => $handle, 'label' => ucfirst($handle)]);
        }

        return $this->regions([
            'source' => true,
            'extra'  => fn(MappingSchemaBuilder $b)  => $b->matrixFields([
                'label'     => 'Hero',
                'blockType' => $blockType,
                'subFields' => $subFields->toArray(),
            ]),
        ]);
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
