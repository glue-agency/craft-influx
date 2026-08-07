<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Cake\Utility\Hash;
use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use DateTime;
use DateTimeZone;
use GlueAgency\Influx\enums\MatrixBlockSource;
use GlueAgency\Influx\exceptions\MappingDepthException;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use Throwable;

/**
 * Behaviour spec for the Matrix mapping strategy — the no-boot half. Compat's
 * block-type discovery and throwaway-element construction, plus the child
 * FieldsService lookup, are stubbed via a testable subclass so the index-zip,
 * child-coercion, addressed gate, error path, and change-detection logic can
 * be exercised without a running Craft.
 *
 * Mappings live under the Feed Me-shaped `blocks` channel: one sub-mapping tree
 * per block-type handle ({@see FieldMapping::blockMappings()}). What those
 * trees' node paths mean depends on the row's `options.blockSource`
 * ({@see MatrixBlockSource}): under the default GROUPED they're absolute paths
 * index-zipped into blocks that come out grouped by type, and under the three
 * LIST_* sources they're relative to one element of a single list node, which
 * emits blocks in feed order. Both keep a continuous `newN` counter.
 */
class MatrixFieldTest extends Unit
{
    public function testEachListItemBecomesABlockInFeedOrder(): void
    {
        $item = new RemoteItem([
            'seasons' => [
                ['year' => 2020, 'summary' => 'a'],
                ['year' => 2021, 'summary' => 'b'],
                ['year' => 2022, 'summary' => 'c'],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year', 'summary']]);
        $blocks = $strategy->parse($this->singleContext($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'year'],
                    'notes' => ['node' => 'summary'],
                ],
            ],
        ]));

        $this->assertSame(['new1', 'new2', 'new3'], array_keys($blocks));

        foreach ([2020, 2021, 2022] as $i => $year) {
            $key = 'new' . ($i + 1);
            $this->assertSame('season', $blocks[$key]['type']);
            $this->assertTrue($blocks[$key]['enabled']);
            // The fake child strategy echoes the resolved value with a marker,
            // proving coercion ran through the child strategy for that block.
            $this->assertSame('coerced:' . $year, $blocks[$key]['fields']['year']);
        }

        // The child received a synthetic single-value item that resolves to
        // exactly this block's value.
        $recorded = $strategy->recordedContexts['year'][1];
        $this->assertSame(2021, $recorded->mapping->resolve($recorded->item));
    }

    public function testASingleElementListYieldsOneBlock(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020, 'summary' => 'only']]]);

        $blocks = $this->strategy(['season' => ['year', 'summary']])->parse($this->singleContext($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'year'],
                    'notes' => ['node' => 'summary'],
                ],
            ],
        ]));

        $this->assertCount(1, $blocks);
        $this->assertSame(['new1'], array_keys($blocks));
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
    }

    public function testInactiveAndUnmappedChildrenNeverContribute(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);

        $blocks = $this->strategy(['season' => ['year']])->parse($this->singleContext($item, [
            'season' => [
                'fields' => [
                    'year' => ['node' => 'year'],
                    // Inactive: no node, no useDefault — contributes nothing.
                    'ghost' => [],
                    // Active but not on the block layout — silently skipped.
                    'absent' => ['node' => 'year'],
                ],
            ],
        ]));

        $this->assertCount(1, $blocks);
        $this->assertSame(['year' => 'coerced:2020'], $blocks['new1']['fields']);
    }

    public function testAddressedIsFalseWhenTheRowNamesNoNode(): void
    {
        $context = $this->context(
            new RemoteItem(['seasons' => [['year' => 2020]]]),
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
        );

        $this->assertFalse($this->strategy(['season' => ['year']])->addressed($context));
    }

    /**
     * The feed spoke — the list is there — but every mapped child of the one
     * item resolves to null, so the block carries its type and nothing else.
     * Full-replace still writes it: the feed is authoritative.
     */
    public function testAListItemWhoseChildrenAllResolveEmptyStillMakesABlock(): void
    {
        $item = new RemoteItem(['seasons' => [['other' => 1, 'year' => '']]]);

        $context = $this->singleContext($item, [
            'season' => ['fields' => ['year' => ['node' => 'year']]],
        ]);
        $strategy = $this->strategy(['season' => ['year']]);

        $this->assertTrue($strategy->addressed($context));
        $this->assertSame(
            ['new1' => ['type' => 'season', 'enabled' => true]],
            $strategy->parse($context),
        );
    }

    public function testUnknownBlockTypeThrows(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, [
            'nope' => ['fields' => ['year' => ['node' => 'year']]],
        ]);

        $this->expectException(MappingValueException::class);
        $this->strategy(['season' => ['year']])->parse($context);
    }

    public function testNativeSubFieldLandsAtBlockTopLevel(): void
    {
        $item = new RemoteItem([
            'seasons' => [
                ['year' => 2020, 'label' => 'First'],
                ['year' => 2021, 'label' => 'Second'],
            ],
        ]);

        $blocks = $this->strategy(['season' => ['year']])->parse($this->singleContext($item, [
            'season' => [
                'fields'       => ['year' => ['node' => 'year']],
                'nativeFields' => ['title' => ['node' => 'label']],
            ],
        ]));

        $this->assertSame('First', $blocks['new1']['title']);
        $this->assertSame('Second', $blocks['new2']['title']);
        $this->assertArrayNotHasKey('title', $blocks['new1']['fields']);
    }

    // -- multi-type behaviour -------------------------------------------------

    public function testPerTypeMappedHandleIsolation(): void
    {
        // The same child handle `label` is mapped on both types to different
        // nodes — each block reads only its own type's mapping.
        $item = new RemoteItem([
            'seasons' => [
                ['season' => ['name' => 'S1']],
                ['quote' => ['author' => 'A1']],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['label'], 'quote' => ['label']]);
        $blocks = $strategy->parse($this->context(
            $item,
            [
                'season' => ['fields' => ['label' => ['node' => 'name']]],
                'quote'  => ['fields' => ['label' => ['node' => 'author']]],
            ],
            node: 'seasons',
        ));

        $this->assertSame('coerced:S1', $blocks['new1']['fields']['label']);
        $this->assertSame('season', $blocks['new1']['type']);
        $this->assertSame('coerced:A1', $blocks['new2']['fields']['label']);
        $this->assertSame('quote', $blocks['new2']['type']);
    }

    // -- list block sources ---------------------------------------------------

    /**
     * The whole point: a feed ordered text, quote, text, text lands in that
     * order. Under GROUPED the same feed comes out text, text, text, quote,
     * because the field's declared type order decides it.
     */
    public function testKeyedListKeepsTheFeedsOwnBlockOrderAcrossTypes(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['season' => ['year' => 2020]],
                ['quote'  => ['text' => 'a quote']],
                ['season' => ['year' => 2021]],
                ['season' => ['year' => 2022]],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context(
            $item,
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_BY_KEY->value],
        ));

        $this->assertSame(['new1', 'new2', 'new3', 'new4'], array_keys($blocks));
        $this->assertSame(
            ['season', 'quote', 'season', 'season'],
            array_column($blocks, 'type'),
        );
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
        $this->assertSame('coerced:a quote', $blocks['new2']['fields']['text']);
        $this->assertSame('coerced:2022', $blocks['new4']['fields']['year']);
    }

    /**
     * The same-type alignment bug, which is what a positional read fixes: the
     * middle block carries no `summary`, and under GROUPED the collapsed list
     * drops that null so the THIRD summary lands on the SECOND block.
     */
    public function testASingleTypeListKeepsBlocksAlignedAcrossAMissingChild(): void
    {
        $data = [
            'content' => [
                ['year' => 2020, 'summary' => 'first'],
                ['year' => 2021],
                ['year' => 2022, 'summary' => 'third'],
            ],
        ];

        $strategy = $this->strategy(['season' => ['year', 'notes']]);
        $mapping = [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'year'],
                    'notes' => ['node' => 'summary'],
                ],
            ],
        ];

        $blocks = $strategy->parse($this->context(
            new RemoteItem($data),
            $mapping,
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        ));

        $this->assertSame('coerced:first', $blocks['new1']['fields']['notes']);
        $this->assertArrayNotHasKey('notes', $blocks['new2']['fields']);
        $this->assertSame('coerced:third', $blocks['new3']['fields']['notes']);

        // The read that would mis-shift it: collapsing the list drops the null
        // the middle item yields, so `content.summary` is a dense two-value
        // list whose second value belongs to the THIRD block.
        $this->assertSame(['first', 'third'], (new RemoteItem($data))->get('content.summary'));
    }

    public function testTheDiscriminatorSourceReadsTheTypeOffEachElement(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['type' => 'quote', 'text' => 'a quote'],
                ['type' => 'season', 'year' => 2020],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context(
            $item,
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_BY_NODE->value],
        ));

        $this->assertSame(['quote', 'season'], array_column($blocks, 'type'));
        $this->assertSame('coerced:a quote', $blocks['new1']['fields']['text']);
    }

    public function testTheDiscriminatorNodeAndTheTypeAliasesAreConfigurable(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['component' => 'seizoen', 'year' => 2020],
                ['component' => 'citaat', 'text' => 'a quote'],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context(
            $item,
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: [
                'blockSource'      => MatrixBlockSource::LIST_BY_NODE->value,
                'typeNode'         => 'component',
                'sourceKey_season' => 'seizoen',
                'sourceKey_quote'  => 'citaat',
            ],
        ));

        $this->assertSame(['season', 'quote'], array_column($blocks, 'type'));
    }

    /**
     * A feed carrying a block type the link doesn't map is data, not a broken
     * mapping — one unmapped type must not take the whole item down.
     */
    public function testAnElementNamingNoConfiguredTypeIsSkipped(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['type' => 'season', 'year' => 2020],
                ['type' => 'gallery', 'images' => ['a.jpg']],
                ['type' => 'season', 'year' => 2021],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context(
            $item,
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_BY_NODE->value],
        ));

        $this->assertSame(['new1', 'new2'], array_keys($blocks));
        $this->assertSame('coerced:2021', $blocks['new2']['fields']['year']);
    }

    /**
     * Metadata beside the payload must not be mistaken for the type key, and
     * the element's own key order decides which of two type keys wins.
     */
    public function testTheKeyedSourceIgnoresSiblingKeysThatNameNoType(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['id' => 4182, 'sort' => 1, 'season' => ['year' => 2020]],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context(
            $item,
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_BY_KEY->value],
        ));

        $this->assertCount(1, $blocks);
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
    }

    public function testAnEmptyListClearsTheFieldRatherThanLeavingItAlone(): void
    {
        $context = $this->context(
            new RemoteItem(['content' => []]),
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        );

        $strategy = $this->strategy(['season' => ['year']]);

        $this->assertTrue($strategy->addressed($context));
        $this->assertSame([], $strategy->parse($context));
    }

    public function testAListSourceIsAddressedByItsOwnNode(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);
        $mapping = ['season' => ['fields' => ['year' => ['node' => 'year']]]];

        // The node the row names is absent from this item — nothing to say.
        $this->assertFalse($strategy->addressed($this->context(
            new RemoteItem(['other' => [['year' => 2020]]]),
            $mapping,
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        )));

        // Present — even though no child node resolves against the whole item,
        // which is the test GROUPED would apply.
        $this->assertTrue($strategy->addressed($this->context(
            new RemoteItem(['content' => [['year' => 2020]]]),
            $mapping,
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        )));
    }

    /**
     * A row naming a list but configuring nothing to read out of it is
     * half-built; clearing the field over that would be the destructive reading
     * of unfinished work.
     */
    public function testAListSourceWithNoActiveChildrenIsNotAddressed(): void
    {
        $this->assertFalse($this->strategy(['season' => ['year']])->addressed($this->context(
            new RemoteItem(['content' => [['year' => 2020]]]),
            ['season' => ['fields' => ['year' => []]]],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        )));
    }

    public function testAListSourceWithoutANodeThrows(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);

        $this->expectException(MappingValueException::class);
        $strategy->parse($this->context(
            new RemoteItem(['content' => [['year' => 2020]]]),
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        ));
    }

    public function testASingleTypeListWithMoreThanOneTypeConfiguredThrows(): void
    {
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);

        $this->expectException(MappingValueException::class);
        $strategy->parse($this->context(
            new RemoteItem(['content' => [['year' => 2020]]]),
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        ));
    }

    public function testANodeHoldingSomethingOtherThanAListThrows(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);

        $this->expectException(MappingValueException::class);
        $strategy->parse($this->context(
            new RemoteItem(['content' => ['year' => 2020]]),
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        ));
    }

    /**
     * An unrecognised stored source — a link written by a newer version of the
     * plugin — reads as the fallback rather than reinterpreting the feed.
     */
    public function testAnUnknownStoredBlockSourceFallsBackToTheDefault(): void
    {
        $item = new RemoteItem(['seasons' => [['season' => ['year' => 2020]]]]);

        $blocks = $this->strategy(['season' => ['year']])->parse($this->context(
            $item,
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            node: 'seasons',
            options: ['blockSource' => 'somethingElse'],
        ));

        $this->assertSame(MatrixBlockSource::LIST_BY_KEY, MatrixBlockSource::fallback());
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
    }

    /**
     * A feed that only REORDERS its blocks must be written through — which the
     * ordered fingerprint comparison already gives, since the two sides are
     * compared as lists rather than as sets.
     */
    public function testReorderingAloneReadsAsAChange(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);
        $context = $this->context(
            new RemoteItem([]),
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
        );

        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 2020]),
            $this->fakeBlock('season', ['year' => 2021]),
        ]);

        $sameOrder = [
            'new1' => ['type' => 'season', 'enabled' => true, 'fields' => ['year' => 2020]],
            'new2' => ['type' => 'season', 'enabled' => true, 'fields' => ['year' => 2021]],
        ];
        $swapped = [
            'new1' => ['type' => 'season', 'enabled' => true, 'fields' => ['year' => 2021]],
            'new2' => ['type' => 'season', 'enabled' => true, 'fields' => ['year' => 2020]],
        ];

        $this->assertFalse($strategy->exposedValueDiffers($context, $current, $sameOrder));
        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $swapped));
    }

    /**
     * The drill-down's Feed column reads each block's raw value off the element
     * it was built from, so an interleaved list reports per block rather than
     * off a type-wide zipped list.
     */
    public function testTheDrillDownReadsRawValuesPerListElement(): void
    {
        $item = new RemoteItem([
            'content' => [
                ['season' => ['year' => 2020]],
                ['quote'  => ['text' => 'a quote']],
                ['season' => ['year' => 2022]],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $context = $this->context(
            $item,
            [
                'season' => ['fields' => ['year' => ['node' => 'year']]],
                'quote'  => ['fields' => ['text' => ['node' => 'text']]],
            ],
            node: 'content',
            options: ['blockSource' => MatrixBlockSource::LIST_BY_KEY->value],
        );

        $children = $strategy->collectChildren($context, $strategy->parse($context), null);

        $this->assertCount(3, $children);
        $this->assertSame([2020, 'a quote', 2022], [
            $this->rowsByHandle($children[0])['year']->rawValue,
            $this->rowsByHandle($children[1])['text']->rawValue,
            $this->rowsByHandle($children[2])['year']->rawValue,
        ]);
    }

    // -- schema ---------------------------------------------------------------

    public function testTitleLeadsTheCardOfABlockTypeThatHasOne(): void
    {
        $strategy = $this->strategy(['season' => ['year', 'notes']]);
        $cards = $this->cards($strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => true],
        ]));

        $this->assertCount(1, $cards);
        $this->assertSame('matrixFields', $cards[0]['type']);
        $this->assertSame('season', $cards[0]['blockType']);

        $subFields = $cards[0]['subFields'];
        $this->assertSame(['title', 'year', 'notes'], array_column($subFields, 'handle'));
        $this->assertSame('nativeFields', $subFields[0]['channel']);
        $this->assertSame('Title', $subFields[0]['label']);
    }

    public function testTheTitleRowTakesTheLayoutsOwnLabel(): void
    {
        // A block type can relabel its title element ("Season name"), and that
        // is what the editor sees on the block — so it names the row too.
        $strategy = $this->strategy(['season' => ['year']]);
        $cards = $this->cards($strategy->exposedSchema([
            [
                'handle'        => 'season',
                'name'          => 'Season',
                'hasTitleField' => true,
                'titleLabel'    => 'Season name',
            ],
        ]));

        $this->assertSame('Season name', $cards[0]['subFields'][0]['label']);
    }

    public function testABlockTypeWithoutATitleFieldGetsNoTitleRow(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);
        $cards = $this->cards($strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
        ]));

        $this->assertSame(['year'], array_column($cards[0]['subFields'], 'handle'));
    }

    public function testCustomRowsCarryNoChannel(): void
    {
        // An absent `channel` IS the custom-field channel — the stored shape
        // that predates the key, so a custom row must never gain one.
        $strategy = $this->strategy(['season' => ['year']]);
        $cards = $this->cards($strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => true],
        ]));

        $this->assertArrayNotHasKey('channel', $cards[0]['subFields'][1]);
    }

    public function testEachBlockTypeGetsItsOwnCard(): void
    {
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $cards = $this->cards($strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
            ['handle' => 'quote', 'name' => 'Quote', 'hasTitleField' => true],
        ]));

        $this->assertSame(['season', 'quote'], array_column($cards, 'blockType'));
        $this->assertSame(['year'], array_column($cards[0]['subFields'], 'handle'));
        $this->assertSame(['title', 'text'], array_column($cards[1]['subFields'], 'handle'));
    }

    public function testTheBlockSourceSelectLeadsTheExtrasAndDefaultsToTheKeyedList(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);
        $nodes = $strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
        ]);

        $this->assertSame('blockSource', $nodes[0]['handle']);
        $this->assertSame('select', $nodes[0]['type']);
        $this->assertSame('listByKey', $nodes[0]['default']);
        $this->assertSame(
            ['listByKey', 'listByNode', 'listSingle'],
            array_column($nodes[0]['options'], 'value'),
        );
    }

    /**
     * The shape a source expects, written in the field's OWN block types — the
     * question a developer has here is what their JSON must look like, and a
     * generic example makes them translate it.
     */
    public function testEachSourceCarriesAWorkedExampleGatedOnIt(): void
    {
        $nodes = $this->strategy(['season' => ['year']])->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
            ['handle' => 'quote', 'name' => 'Quote', 'hasTitleField' => false],
        ]);

        $examples = [];

        foreach ($nodes as $node) {
            if (($node['type'] ?? null) === 'note' && isset($node['example'])) {
                $examples[$node['showIf'][0]['equals']] = $node['example'];
            }
        }

        $this->assertSame(['listByKey', 'listByNode', 'listSingle'], array_keys($examples));
        $this->assertStringContainsString('{ "season": { "year": "…" } }', $examples['listByKey']);
        $this->assertStringContainsString('{ "type": "season", "year": "…" }', $examples['listByNode']);
        $this->assertStringContainsString('{ "year": "…" }', $examples['listSingle']);
        // The second type appears in the two that can tell types apart.
        $this->assertStringContainsString('"quote"', $examples['listByKey']);
        $this->assertStringNotContainsString('"quote"', $examples['listSingle']);
    }

    public function testTheTypeNodeShowsOnlyForTheDiscriminatorSource(): void
    {
        $strategy = $this->strategy(['season' => ['year']]);
        $nodes = $strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
        ]);

        $typeNode = $this->nodeByHandle($nodes, 'typeNode');

        $this->assertSame('type', $typeNode['default']);
        $this->assertSame(
            [['handle' => 'blockSource', 'equals' => 'listByNode']],
            $typeNode['showIf'],
        );
    }

    /**
     * One alias box per block type, defaulting to the handle, and shown only for
     * the two sources that match a feed key against it.
     */
    public function testEachBlockTypeGetsAFeedKeyBoxForTheKeyMatchingSources(): void
    {
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $nodes = $strategy->exposedSchema([
            ['handle' => 'season', 'name' => 'Season', 'hasTitleField' => false],
            ['handle' => 'quote', 'name' => 'Quote', 'hasTitleField' => false],
        ]);

        foreach (['season', 'quote'] as $typeHandle) {
            $alias = $this->nodeByHandle($nodes, 'sourceKey_' . $typeHandle);

            $this->assertSame($typeHandle, $alias['default']);
            $this->assertSame(
                [['handle' => 'blockSource', 'in' => ['listByKey', 'listByNode']]],
                $alias['showIf'],
            );
        }
    }

    public function testAFieldWithoutBlockTypesRendersANote(): void
    {
        $nodes = $this->strategy([])->exposedSchema([]);

        $this->assertCount(1, $nodes);
        $this->assertSame('note', $nodes[0]['type']);
    }

    // -- change detection -----------------------------------------------------

    public function testValueDiffersIsFalseForIdenticalCurrentAndIncoming(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('season', ['year' => 'coerced:2021']),
        ]);

        $this->assertFalse($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueWhenAChildValueDiffers(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('season', ['year' => 'coerced:9999']),
        ]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueOnBlockCountMismatch(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([$this->fakeBlock('season', ['year' => 'coerced:2020'])]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueWhenCurrentHoldsAnUnconfiguredType(): void
    {
        // Incoming has one configured season block; the current field also
        // carries a block of an UNconfigured type. The feed is authoritative —
        // the comparison must differ so the replace drops the stray block.
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('quote', ['text' => 'leftover']),
        ]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testBoolLeafFingerprintsSymmetrically(): void
    {
        // A Lightswitch child yields a real bool on the incoming side and Craft
        // serializes the stored one as a bool too, so both fingerprint sides run
        // through the shared normaliser's bool case: false is a value, not
        // emptiness, so a re-applied flag must not trigger a rebuild.
        $strategy = $this->strategy(
            ['season' => ['featured']],
            ['featured' => static fn(mixed $raw): bool => Lightswitch::coerce($raw)],
        );
        $context = $this->singleContext(new RemoteItem(['seasons' => [['on' => 'yes'], ['on' => 'no']]]), [
            'season' => ['fields' => ['featured' => ['node' => 'on']]],
        ]);

        $incoming = $strategy->parse($context);
        $this->assertSame([true, false], [$incoming['new1']['fields']['featured'], $incoming['new2']['fields']['featured']]);

        $same = $this->fakeQuery([
            $this->fakeBlock('season', ['featured' => true]),
            $this->fakeBlock('season', ['featured' => false]),
        ]);
        $this->assertFalse($strategy->exposedValueDiffers($context, $same, $incoming));

        $storedAsInt = $this->fakeQuery([
            $this->fakeBlock('season', ['featured' => 1]),
            $this->fakeBlock('season', ['featured' => 0]),
        ]);
        $this->assertFalse(
            $strategy->exposedValueDiffers($context, $storedAsInt, $incoming),
            'A stored 0/1 is the same flag as an incoming false/true — no needless rebuild.',
        );

        $flipped = $this->fakeQuery([
            $this->fakeBlock('season', ['featured' => true]),
            $this->fakeBlock('season', ['featured' => true]),
        ]);
        $this->assertTrue($strategy->exposedValueDiffers($context, $flipped, $incoming));
    }

    public function testDateLeafFingerprintsByInstant(): void
    {
        // A Date child yields a DateTime; the shared normaliser reduces both
        // sides to a timestamp, so the same instant in another timezone is not a
        // change while a second's shift is.
        $strategy = $this->strategy(
            ['season' => ['published']],
            ['published' => static fn(mixed $raw): ?DateTime => Date::tryParse($raw, 'Y-m-d H:i:s')],
        );
        $context = $this->singleContext(new RemoteItem(['seasons' => [['at' => '2024-03-02 10:00:00']]]), [
            'season' => ['fields' => ['published' => ['node' => 'at']]],
        ]);

        $incoming = $strategy->parse($context);

        $sameInstant = $this->fakeQuery([$this->fakeBlock('season', [
            'published' => new DateTime('2024-03-02 11:00:00', new DateTimeZone('Europe/Brussels')),
        ])]);
        $this->assertFalse($strategy->exposedValueDiffers($context, $sameInstant, $incoming));

        $shifted = $this->fakeQuery([$this->fakeBlock('season', [
            'published' => new DateTime('2024-03-02 10:00:01', new DateTimeZone('UTC')),
        ])]);
        $this->assertTrue($strategy->exposedValueDiffers($context, $shifted, $incoming));
    }

    public function testStoredSerializedDateLeafFingerprintsByInstant(): void
    {
        // The regression: currentFingerprint() reads the stored side through
        // getSerializedFieldValues(), where Craft has already rendered the
        // DateTime as a string, while the incoming side is still a DateTime. Both
        // fingerprint sides route their leaves through the CHILD strategy, so the
        // real Date strategy stands in for the child here — its normalisation is
        // the thing under test. Without it a date leaf reads as changed on every
        // single sync and the element is saved for nothing.
        $strategy = $this->strategy(['season' => ['published']], realChild: new Date());
        $context = $this->singleContext(new RemoteItem(['seasons' => [['at' => '2024-03-02 10:00:00']]]), [
            'season' => [
                'fields' => [
                    'published' => [
                        'node'    => 'at',
                        'options' => ['format' => 'Y-m-d H:i:s'],
                    ],
                ],
            ],
        ]);

        $incoming = $strategy->parse($context);
        $this->assertInstanceOf(DateTime::class, $incoming['new1']['fields']['published']);

        $sameInstant = $this->fakeQuery([$this->fakeBlock('season', [
            'published' => '2024-03-02T10:00:00+00:00',
        ])]);
        $this->assertFalse(
            $strategy->exposedValueDiffers($context, $sameInstant, $incoming),
            'A stored ISO-8601 string and an incoming DateTime for the same instant are not a change.',
        );

        // Same instant written with another offset — still not a change.
        $otherOffset = $this->fakeQuery([$this->fakeBlock('season', [
            'published' => '2024-03-02T11:00:00+01:00',
        ])]);
        $this->assertFalse($strategy->exposedValueDiffers($context, $otherOffset, $incoming));

        $oneSecondLater = $this->fakeQuery([$this->fakeBlock('season', [
            'published' => '2024-03-02T10:00:01+00:00',
        ])]);
        $this->assertTrue($strategy->exposedValueDiffers($context, $oneSecondLater, $incoming));
    }

    public function testEmptyOrUnparseableStoredDateLeafIsStillAChange(): void
    {
        // Reading an instant out of the stored side must not make emptiness look
        // like one: a date leaf the stored block doesn't carry — cleared, or
        // holding something that is no date at all — still differs from a real
        // incoming instant.
        $strategy = $this->strategy(['season' => ['published']], realChild: new Date());
        $context = $this->singleContext(new RemoteItem(['seasons' => [['at' => '2024-03-02 10:00:00']]]), [
            'season' => [
                'fields' => [
                    'published' => [
                        'node'    => 'at',
                        'options' => ['format' => 'Y-m-d H:i:s'],
                    ],
                ],
            ],
        ]);

        $incoming = $strategy->parse($context);

        $cleared = $this->fakeQuery([$this->fakeBlock('season', ['published' => null])]);
        $this->assertTrue($strategy->exposedValueDiffers($context, $cleared, $incoming));

        $garbage = $this->fakeQuery([$this->fakeBlock('season', ['published' => 'not-a-date'])]);
        $this->assertTrue($strategy->exposedValueDiffers($context, $garbage, $incoming));
    }

    public function testValueDiffersFallsBackToParentForNonQueryCurrent(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);

        // A scalar current can't be a block query — parent normalise/compare
        // decides, and a scalar vs. array always differs.
        $this->assertTrue($strategy->exposedValueDiffers($context, 'not-a-query', $incoming));
    }

    // -- child drill-down -----------------------------------------------------

    public function testChildrenKindIsBlocks(): void
    {
        $this->assertSame('blocks', $this->strategy([])->childrenKind());
    }

    public function testFingerprintIdenticalBlocksReadAsUnchangedChildren(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $blocks = [
            $this->fakeBlock('season', ['year' => 'coerced:2020'], ['title' => 'Winter 2020']),
            $this->fakeBlock('season', ['year' => 'coerced:2021'], ['title' => 'Winter 2021']),
        ];
        $current = $this->fakeQuery($blocks);

        $children = $strategy->collectChildren($context, $incoming, $current);

        $this->assertCount(2, $children);
        $this->assertSame(['unchanged', 'unchanged'], array_column($children, 'action'));
        // The feed maps no title, so the partner block's own one labels the row.
        $this->assertSame('Winter 2020', $children[0]->title);
        $this->assertSame('season', $children[0]->blockType);
        // An untouched block is a saved element — the drill-down chips it.
        $this->assertSame($blocks[0], $children[0]->element);
        $this->assertSame($blocks[1], $children[1]->element);
        $this->assertNotNull($children[0]->labelElement);

        $rows = $this->rowsByHandle($children[1]);
        $this->assertSame(['year'], array_keys($rows));
        $this->assertFalse($rows['year']->changed);
        $this->assertFalse($rows['year']->native);
        $this->assertSame(2021, $rows['year']->rawValue);
        $this->assertSame('coerced:2021', $rows['year']->parsedValue);
        $this->assertSame('coerced:2021', $rows['year']->currentValue);
        $this->assertSame('year', $rows['year']->node);
    }

    public function testChangedBlockReadsAsAnAddAgainstItsPartner(): void
    {
        // Same type, same position, one differing leaf: full-replace means the
        // block is an ADD, and the partner block is only there to fill the
        // Current column and flag which leaf moved.
        $item = new RemoteItem(['seasons' => [['year' => 2020, 'summary' => 'a']]]);
        $context = $this->singleContext($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'year'],
                    'notes' => ['node' => 'summary'],
                ],
            ],
        ], dryRun: true);
        $strategy = $this->strategy(['season' => ['year', 'notes']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([$this->fakeBlock('season', [
            'year'  => 'coerced:1999',
            'notes' => 'coerced:a',
        ])]);

        $children = $strategy->collectChildren($context, $incoming, $current);

        $this->assertCount(1, $children);
        $this->assertSame('would-add', $children[0]->action);
        // A full-replace add is a block that doesn't exist yet — the partner only
        // fills the Current column, it is not this child's identity.
        $this->assertNull($children[0]->element);

        $rows = $this->rowsByHandle($children[0]);
        $this->assertTrue($rows['year']->changed);
        $this->assertSame('coerced:1999', $rows['year']->currentValue);
        $this->assertFalse($rows['notes']->changed, 'Only the differing leaf is flagged.');
        $this->assertSame('coerced:a', $rows['notes']->currentValue);
    }

    public function testIncomingBlockWithoutAPartnerHasNoCurrentValues(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, [
            'season' => ['fields' => ['year' => ['node' => 'year']]],
        ], dryRun: true);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        // The first block is the current one; the second has nothing left to pair
        // with.
        $current = $this->fakeQuery([$this->fakeBlock('season', ['year' => 'coerced:2020'])]);

        $children = $strategy->collectChildren($context, $incoming, $current);

        $this->assertSame(['unchanged', 'would-add'], array_column($children, 'action'));

        $rows = $this->rowsByHandle($children[1]);
        $this->assertNull($rows['year']->currentValue);
        $this->assertTrue($rows['year']->changed, 'Nothing to compare against — a parsed value is new.');
    }

    public function testLeftoverCurrentBlockReadsAsARemoval(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, [
            'season' => ['fields' => ['year' => ['node' => 'year']]],
        ], dryRun: true);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $dropped = $this->fakeBlock('season', ['year' => 'coerced:1999'], ['title' => 'Winter 1999']);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $dropped,
        ]);

        $children = $strategy->collectChildren($context, $incoming, $current);

        $this->assertSame(['unchanged', 'would-remove'], array_column($children, 'action'));
        $this->assertSame('Winter 1999', $children[1]->title);
        // A dropped block still exists right now, so it stays navigable.
        $this->assertSame($dropped, $children[1]->element);
        $this->assertSame($dropped, $children[1]->labelElement);

        $rows = $this->rowsByHandle($children[1]);
        $this->assertSame('coerced:1999', $rows['year']->currentValue);
        $this->assertNull($rows['year']->rawValue);
        $this->assertNull($rows['year']->parsedValue);
        $this->assertNull($rows['year']->changed, 'A dropped block has no feed side to compare.');
    }

    public function testRemovedBlockOfAnUnconfiguredTypeShowsNoRows(): void
    {
        // `quote` is a real block type the mapping never configures, so the block
        // has no mapped handles to show — it still reads as a removal.
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, [
            'season' => ['fields' => ['year' => ['node' => 'year']]],
        ], dryRun: true);
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('quote', ['text' => 'leftover'], ['title' => 'Pull quote']),
        ]);

        $children = $strategy->collectChildren($context, $incoming, $current);

        $this->assertSame(['unchanged', 'would-remove'], array_column($children, 'action'));
        $this->assertSame('Pull quote', $children[1]->title);
        $this->assertSame('quote', $children[1]->blockType);
        $this->assertSame([], $children[1]->mappingResults);
    }

    public function testPerIndexMissingChildValueIsUnaddressed(): void
    {
        // The third list item carries no `summary`, so that block's row is the
        // per-item missing one — amber, not a silent null, and not a change.
        $item = new RemoteItem(['seasons' => [
            ['year' => 1, 'summary' => 'x'],
            ['year' => 2, 'summary' => 'y'],
            ['year' => 3],
        ]]);
        $context = $this->singleContext($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'year'],
                    'notes' => ['node' => 'summary'],
                ],
            ],
        ]);
        $strategy = $this->strategy(['season' => ['year', 'notes']]);

        $incoming = $strategy->parse($context);
        $children = $strategy->collectChildren($context, $incoming, $this->fakeQuery([]));

        $this->assertCount(3, $children);

        $rows = $this->rowsByHandle($children[2]);
        $this->assertTrue($rows['notes']->unaddressed);
        $this->assertFalse($rows['notes']->changed);
        $this->assertNull($rows['notes']->rawValue);
        $this->assertNull($rows['notes']->parsedValue);
        $this->assertFalse($rows['year']->unaddressed);
        $this->assertTrue($rows['year']->changed);
        $this->assertSame(3, $rows['year']->rawValue);
    }

    public function testNativeChildRowReadsThePartnerBlocksAttribute(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020, 'label' => 'First']]]);
        $context = $this->singleContext($item, [
            'season' => [
                'fields'       => ['year' => ['node' => 'year']],
                'nativeFields' => ['title' => ['node' => 'label']],
            ],
        ], dryRun: true);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020'], ['title' => 'Old']),
        ]);

        $children = $strategy->collectChildren($context, $incoming, $current);
        $rows = $this->rowsByHandle($children[0]);

        $this->assertSame('would-add', $children[0]->action);
        $this->assertSame(['title', 'year'], array_keys($rows), 'Native rows come first.');
        $this->assertTrue($rows['title']->native);
        $this->assertSame('First', $rows['title']->parsedValue);
        $this->assertSame('Old', $rows['title']->currentValue);
        $this->assertTrue($rows['title']->changed);
        $this->assertFalse($rows['year']->changed);
    }

    public function testRealRunChildrenCarryCommittedActionLabels(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $blocks = ['season' => ['fields' => ['year' => ['node' => 'year']]]];
        $strategy = $this->strategy(['season' => ['year']]);

        // One identical block (exact pass), one differing block the second
        // incoming row pairs with positionally, one block nobody claims.
        $currentBlocks = [
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('season', ['year' => 'coerced:9999']),
            $this->fakeBlock('season', ['year' => 'coerced:8888']),
        ];

        $real = $this->singleContext($item, $blocks);
        $children = $strategy->collectChildren($real, $strategy->parse($real), $this->fakeQuery($currentBlocks));
        $this->assertSame(['unchanged', 'added', 'removed'], array_column($children, 'action'));

        $dry = $this->singleContext($item, $blocks, dryRun: true);
        $children = $strategy->collectChildren($dry, $strategy->parse($dry), $this->fakeQuery($currentBlocks));
        $this->assertSame(['unchanged', 'would-add', 'would-remove'], array_column($children, 'action'));
    }

    public function testMappedNativeTitleOutranksThePartnerBlocksOwn(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020, 'label' => 'Winter 2020']]]);
        $context = $this->singleContext($item, [
            'season' => [
                'fields'       => ['year' => ['node' => 'year']],
                'nativeFields' => ['title' => ['node' => 'label']],
            ],
        ]);
        $strategy = $this->strategy(['season' => ['year']]);

        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020'], ['title' => 'Stale title']),
        ]);

        $children = $strategy->collectChildren($context, $strategy->parse($context), $current);

        $this->assertSame('Winter 2020', $children[0]->title, 'The feed title is what this sync writes.');
    }

    public function testChildWithNoTitleAnywhereCarriesNone(): void
    {
        // The feed maps no title and there is no partner to borrow one from, so
        // the child has nothing to be labelled by — a null title is the
        // drill-down's cue to fall back to the block's ordinal.
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $children = $strategy->collectChildren($context, $strategy->parse($context), $this->fakeQuery([]));

        $this->assertNull($children[0]->title);
        $this->assertNull($children[0]->element);
    }

    public function testLabelBlockIsMemoizedPerType(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $children = $strategy->collectChildren($context, $strategy->parse($context), $this->fakeQuery([]));

        $this->assertSame(
            $children[0]->labelElement,
            $children[1]->labelElement,
            'One throwaway block per type per collection, not per block.',
        );
    }

    public function testUnreadableCurrentValueLeavesEveryChildUnpaired(): void
    {
        // A brand-new element's block query can't be walked; the drill-down
        // degrades to "everything is an add" rather than taking the row down.
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $throwing = new class() {
            public function all(): array
            {
                throw new MappingValueException('no owner');
            }
        };

        $children = $strategy->collectChildren($context, $strategy->parse($context), $throwing);

        $this->assertSame(['added'], array_column($children, 'action'));
        $this->assertNull($this->rowsByHandle($children[0])['year']->currentValue);
    }

    public function testNoChildrenWhenThereIsNothingToShow(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        // Neither side holds a block…
        $this->assertNull($strategy->collectChildren($context, [], $this->fakeQuery([])));
        // …the field was left untouched (no parsed array at all)…
        $this->assertNull($strategy->collectChildren($context, null, $this->fakeQuery([])));
        // …and the row configures no block-type tree.
        $bare = $this->singleContext($item, []);
        $this->assertNull($strategy->collectChildren($bare, ['new1' => ['type' => 'season']], $this->fakeQuery([])));
    }

    public function testChildrenAreCappedAtTheResultLimit(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext($item, ['season' => ['fields' => ['year' => ['node' => 'year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        // Handed straight to the derivation: a feed that fans out this far is the
        // case the cap exists for, and parse()'s zip isn't what's under test.
        $incoming = [];

        for ($i = 1; $i <= 120; $i++) {
            $incoming['new' . $i] = [
                'type'    => 'season',
                'enabled' => true,
                'fields'  => ['year' => 'coerced:' . $i],
            ];
        }

        $children = $strategy->collectChildren($context, $incoming, $this->fakeQuery([]));

        $this->assertCount(100, $children);
    }

    // -- post-commit identity back-fill ---------------------------------------

    public function testChildrenPairWithTheSavedBlocksOfTheirTypeInOrder(): void
    {
        // What an added child looks like when the owner's save has run: no
        // element (it had no id at derivation time), and a title only where the
        // feed mapped one.
        $children = [
            new ChildResult(title: null, blockType: 'season', action: 'added'),
            new ChildResult(title: 'Winter 2021', blockType: 'season', action: 'added'),
            new ChildResult(title: null, blockType: 'quote', action: 'added'),
        ];

        $saved = [
            $this->fakeBlock('season', [], ['title' => 'Winter 2020']),
            $this->fakeBlock('season', [], ['title' => 'Stale 2021']),
            $this->fakeBlock('quote', [], ['title' => 'Pull quote']),
        ];

        (new Matrix())->attachSavedChildren($this->owner($this->fakeQuery($saved)), 'seasons', $children);

        $this->assertSame($saved[0], $children[0]->element);
        $this->assertSame($saved[1], $children[1]->element);
        $this->assertSame($saved[2], $children[2]->element);

        $this->assertSame('Winter 2020', $children[0]->title, 'A title-less child takes the saved block title.');
        $this->assertSame('Winter 2021', $children[1]->title, 'A resolved title is the derivation verdict and stands.');
        $this->assertSame('Pull quote', $children[2]->title);

        // The label carrier was null here (a type whose throwaway block failed to
        // build), so the saved block stands in for it too.
        $this->assertSame($saved[0], $children[0]->labelElement);
    }

    public function testPairingCountsPerTypeRatherThanPositionally(): void
    {
        // The children arrive interleaved while the saved blocks are grouped by
        // type — pairing by position would chip the wrong element onto every row.
        $children = [
            new ChildResult(blockType: 'quote', action: 'added'),
            new ChildResult(blockType: 'season', action: 'added'),
            new ChildResult(blockType: 'season', action: 'added'),
        ];

        $saved = [
            $this->fakeBlock('season', [], ['title' => 'S1']),
            $this->fakeBlock('season', [], ['title' => 'S2']),
            $this->fakeBlock('quote', [], ['title' => 'Q1']),
        ];

        (new Matrix())->attachSavedChildren($this->owner($this->fakeQuery($saved)), 'seasons', $children);

        $this->assertSame(['Q1', 'S1', 'S2'], array_column($children, 'title'));
        $this->assertSame($saved[2], $children[0]->element);
        $this->assertSame($saved[0], $children[1]->element);
        $this->assertSame($saved[1], $children[2]->element);
    }

    public function testACountMismatchSkipsThatTypeWhole(): void
    {
        // Two season children but one saved season block — the type is skipped
        // rather than mislabelled, while a type whose counts DO match still fills.
        $children = [
            new ChildResult(blockType: 'season', action: 'added'),
            new ChildResult(blockType: 'season', action: 'added'),
            new ChildResult(blockType: 'quote', action: 'added'),
        ];

        $saved = [
            $this->fakeBlock('season', [], ['title' => 'S1']),
            $this->fakeBlock('quote', [], ['title' => 'Q1']),
        ];

        (new Matrix())->attachSavedChildren($this->owner($this->fakeQuery($saved)), 'seasons', $children);

        $this->assertNull($children[0]->element);
        $this->assertNull($children[0]->title);
        $this->assertNull($children[1]->element);
        $this->assertSame($saved[1], $children[2]->element, 'A type whose counts line up still pairs.');
        $this->assertSame('Q1', $children[2]->title);
    }

    public function testRemovedChildrenNeitherPairNorConsumeASavedBlock(): void
    {
        // A removed block is out of the element, so it is not in the saved set:
        // the ONE saved season block belongs to the added child behind it.
        foreach (['removed', 'would-remove'] as $label) {
            $dropped = $this->fakeBlock('season', [], ['title' => 'Winter 1999']);
            $children = [
                new ChildResult(title: 'Winter 1999', blockType: 'season', element: $dropped, action: $label),
                new ChildResult(blockType: 'season', action: 'added'),
            ];

            $saved = [$this->fakeBlock('season', [], ['title' => 'Winter 2020'])];

            (new Matrix())->attachSavedChildren($this->owner($this->fakeQuery($saved)), 'seasons', $children);

            $this->assertSame($dropped, $children[0]->element, "The {$label} child keeps the block it stands for.");
            $this->assertSame('Winter 1999', $children[0]->title);
            $this->assertSame($saved[0], $children[1]->element);
            $this->assertSame('Winter 2020', $children[1]->title);
        }
    }

    public function testAlreadyIdentifiedChildrenAreLeftExactlyAsTheyAre(): void
    {
        // An unchanged child was paired with a real block at derivation time; the
        // back-fill must not re-point it at whatever now sits in that slot.
        $partner = $this->fakeBlock('season', [], ['title' => 'Winter 2020']);
        $children = [
            new ChildResult(
                title: 'Winter 2020',
                blockType: 'season',
                element: $partner,
                labelElement: $partner,
                action: 'unchanged',
            ),
        ];

        $saved = [$this->fakeBlock('season', [], ['title' => 'Rebuilt 2020'])];

        (new Matrix())->attachSavedChildren($this->owner($this->fakeQuery($saved)), 'seasons', $children);

        $this->assertSame($partner, $children[0]->element);
        $this->assertSame($partner, $children[0]->labelElement);
        $this->assertSame('Winter 2020', $children[0]->title);
    }

    public function testAnUnreadableFieldValueIsANoOp(): void
    {
        $children = [new ChildResult(blockType: 'season', action: 'added')];

        // A field read that throws outright…
        (new Matrix())->attachSavedChildren(
            $this->owner(new MappingValueException('no owner')),
            'seasons',
            $children,
        );
        $this->assertNull($children[0]->element);

        // …a value that is no walkable block query at all…
        (new Matrix())->attachSavedChildren($this->owner('not-a-query'), 'seasons', $children);
        $this->assertNull($children[0]->element);

        // …and a query whose walk throws.
        $throwing = new class() {
            public function all(): array
            {
                throw new MappingValueException('no owner');
            }
        };
        (new Matrix())->attachSavedChildren($this->owner($throwing), 'seasons', $children);
        $this->assertNull($children[0]->element);
        $this->assertNull($children[0]->title);
    }

    public function testDescendPastMaxDepthThrows(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->singleContext(
            $item,
            ['season' => ['fields' => ['year' => ['node' => 'year']]]],
            depth: FieldContext::MAX_DEPTH,
        );
        $strategy = $this->strategy(['season' => ['year']]);

        $this->expectException(MappingDepthException::class);
        $strategy->parse($context);
    }

    public function testHashInsertRoundTripsThroughRemoteItemForNumericSegments(): void
    {
        $item = new RemoteItem(Hash::insert([], 'items.0.name', 'x'));
        $this->assertSame('x', $item->get('items.0.name'));

        $flat = new RemoteItem(Hash::insert([], 'seasons.year', 2020));
        $this->assertSame(2020, $flat->get('seasons.year'));
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * A Matrix strategy with block-type discovery, throwaway-element creation
     * and child-strategy lookup stubbed out. Records the FieldContext each
     * child receives (for assertions) and coerces child values to
     * `coerced:<value>` so per-block resolution is observable.
     *
     * @param array<string, list<string>> $typeLayouts block-type handle (in
     * declared order) → the handles that type's fake layout exposes
     * @param array<string, callable> $childValues per-handle coercion, for leaves
     * whose real strategy yields a typed value (a bool, a date) rather than a
     * string — the default marker coercion can't stand in for those.
     * @param ?Field $realChild a REAL strategy standing in for every child, for
     * specs whose subject is the child strategy's own behaviour (a date leaf's
     * comparison normalisation) rather than the zip mechanics.
     */
    protected function strategy(
        array $typeLayouts,
        array $childValues = [],
        ?Field $realChild = null,
    ): Matrix {
        $test = $this;

        return new class($typeLayouts, $childValues, $realChild, $test) extends Matrix {
            /** @var array<string, list<string>> */
            public array $typeLayouts = [];

            /** @var array<string, callable> */
            public array $childValues = [];

            public ?Field $realChild = null;

            public MatrixFieldTest $test;

            /** @var array<string, list<FieldContext>> */
            public array $recordedContexts = [];

            public function __construct(
                array $typeLayouts,
                array $childValues,
                ?Field $realChild,
                MatrixFieldTest $test,
            ) {
                $this->typeLayouts = $typeLayouts;
                $this->childValues = $childValues;
                $this->realChild = $realChild;
                $this->test = $test;
            }

            /** @var list<array<string, mixed>> */
            public array $descriptors = [];

            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }

            /**
             * Build the mapping schema off the given block-type descriptors,
             * each given the fake layout of its handle in `$typeLayouts` (plus
             * a relabelled title element when the descriptor asks for one).
             *
             * @param list<array<string, mixed>> $descriptors
             */
            public function exposedSchema(array $descriptors): array
            {
                $this->descriptors = array_map(fn(array $descriptor): array => $descriptor + [
                    'layout' => $this->test->fakeLayout(
                        $this->typeLayouts[$descriptor['handle']] ?? [],
                        $descriptor['titleLabel'] ?? null,
                    ),
                ], $descriptors);

                return $this->schema($this->test->fakeCraftField())->toArray()['extra'] ?? [];
            }

            protected function blockTypeDescriptors(CraftFieldInterface $field): array
            {
                return $this->descriptors;
            }

            /**
             * No booted plugin to ask, so every block field's row reads as text
             * — the editor a plain-text child would get anyway.
             */
            protected function childRowFor(CraftFieldInterface $craftField): array
            {
                return ['default' => ['type' => 'text'], 'extra' => []];
            }

            protected function blockTypeHandles(FieldContext $context): array
            {
                return array_keys($this->typeLayouts);
            }

            protected function blockElement(FieldContext $context, string $typeHandle): ?ElementInterface
            {
                return $this->test->fakeBlockElement($this->typeLayouts[$typeHandle] ?? []);
            }

            protected function childStrategy(FieldContext $context, CraftFieldInterface $childCraftField): Field
            {
                if ($this->realChild !== null) {
                    return $this->realChild;
                }

                $strategy = $this;

                return new class($strategy) extends Field {
                    public $owner;

                    public function __construct($owner)
                    {
                        $this->owner = $owner;
                    }

                    public function parse(FieldContext $context): mixed
                    {
                        $this->owner->recordedContexts[$context->handle][] = $context;
                        $raw = $context->mapping->resolve($context->item);
                        $coerce = $this->owner->childValues[$context->handle] ?? null;

                        return $coerce ? $coerce($raw) : 'coerced:' . $raw;
                    }
                };
            }
        };
    }

    /**
     * A throwaway block element whose field layout exposes a mock craft field
     * for each of the given handles.
     *
     * @param list<string> $handles
     */
    public function fakeBlockElement(array $handles): ElementInterface
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->willReturnCallback(
            function(string $handle) use ($handles): ?CraftFieldInterface {
                return in_array($handle, $handles, true)
                    ? $this->createMock(CraftFieldInterface::class)
                    : null;
            },
        );

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);

        return $element;
    }

    /**
     * A block type's fake field layout: a custom field per handle (the schema
     * reads name + handle off each), and — when a label is given — a relabelled
     * title element for the native Title row to name itself after.
     *
     * @param list<string> $handles
     */
    public function fakeLayout(array $handles, ?string $titleLabel = null): FieldLayout
    {
        // Real field mocks: besides a handle and a name, the schema asks each
        // custom field's own strategy which default editor its row should carry.
        $customFields = array_map(function(string $handle): CraftFieldInterface {
            $field = $this->createMock(PlainText::class);
            $field->handle = $handle;
            $field->name = ucfirst($handle);

            return $field;
        }, $handles);

        $titleElement = null;

        if ($titleLabel !== null) {
            $titleElement = $this->createMock(EntryTitleField::class);
            $titleElement->method('label')->willReturn($titleLabel);
        }

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn($customFields);
        $layout->method('getFirstElementByType')->willReturn($titleElement);

        return $layout;
    }

    /**
     * The Craft field a schema is built for — a bare mock, since every block
     * type it would be asked for comes from the stubbed descriptors instead.
     */
    public function fakeCraftField(): CraftFieldInterface
    {
        return $this->createMock(CraftFieldInterface::class);
    }

    /**
     * A fake current block exposing getType()->handle and
     * getSerializedFieldValues() the way currentFingerprint() reads them. A real
     * {@see Element} subclass (constructor skipped, as elsewhere in the suite, so
     * Element::init()'s Craft dependencies stay out of it), because a saved block
     * IS an element: the drill-down narrows an unchanged or removed block to
     * {@see ChildResult::$element} so the reader can chip and link straight to it.
     * getType() is added rather than overridden — it lives on the concrete block
     * classes (Craft 5 Entry, Craft 4 MatrixBlock), not on Element.
     *
     * Native attributes are assigned as element attributes (`title`), which is
     * how the fingerprint and the drill-down's native rows read them
     * (`$block->{$handle} ?? null`).
     *
     * @param array<string, mixed> $serialized
     * @param array<string, mixed> $natives
     */
    public function fakeBlock(string $typeHandle, array $serialized, array $natives = []): ElementInterface
    {
        $type = new class($typeHandle) {
            public string $handle;

            public function __construct(string $handle)
            {
                $this->handle = $handle;
            }
        };

        $block = new class() extends Element {
            public object $type;

            /** @var array<string, mixed> */
            public array $serialized = [];

            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }

            public function getType(): object
            {
                return $this->type;
            }

            /**
             * @param list<string>|null $fieldHandles
             * @return array<string, mixed>
             */
            public function getSerializedFieldValues(?array $fieldHandles = null): array
            {
                if ($fieldHandles === null) {
                    return $this->serialized;
                }

                return array_intersect_key($this->serialized, array_flip($fieldHandles));
            }
        };

        $block->type = $type;
        $block->serialized = $serialized;

        foreach ($natives as $handle => $value) {
            $block->{$handle} = $value;
        }

        return $block;
    }

    /**
     * An owner element handing back one value for every field read — the
     * post-save read {@see Matrix::attachSavedChildren()} pairs its children
     * against. A Throwable value is thrown instead of returned, standing in for a
     * field that can't be read at all.
     */
    public function owner(mixed $value): ElementInterface
    {
        $owner = $this->createMock(ElementInterface::class);

        if ($value instanceof Throwable) {
            $owner->method('getFieldValue')->willThrowException($value);
        } else {
            $owner->method('getFieldValue')->willReturn($value);
        }

        return $owner;
    }

    /**
     * A fake element query exposing all() over the given blocks.
     *
     * @param list<object> $blocks
     */
    public function fakeQuery(array $blocks): object
    {
        return new class($blocks) {
            /** @var list<object> */
            public array $blocks;

            public function __construct(array $blocks)
            {
                $this->blocks = $blocks;
            }

            public function all(): array
            {
                return $this->blocks;
            }
        };
    }

    /**
     * A Matrix top-level FieldContext. `$blocks` is the per-block-type
     * sub-mapping tree map ({typeHandle: {fields, nativeFields}}), wrapped into
     * the mapping's `blocks` channel; `$node` and `$options` are the row's own
     * source node and settings, which only the list block sources read.
     *
     * @param array<string, mixed> $blocks
     * @param array<string, mixed> $options
     */
    protected function context(
        RemoteItem $item,
        array $blocks,
        ?string $node = null,
        array $options = [],
        int $depth = 0,
        bool $dryRun = false,
    ): FieldContext {
        return new FieldContext(
            craftField: $this->createMock(CraftFieldInterface::class),
            handle: 'seasons',
            mapping: FieldMapping::fromConfig('seasons', [
                'blocks'  => $blocks,
                'node'    => $node,
                'options' => $options,
            ]),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            dryRun: $dryRun,
            depth: $depth,
        );
    }

    /**
     * The common case in shorthand: a `seasons` list of one block type, read
     * positionally. Most specs care about what a block ends up holding rather
     * than about how its type was named.
     *
     * @param array<string, mixed> $blocks
     */
    protected function singleContext(
        RemoteItem $item,
        array $blocks,
        int $depth = 0,
        bool $dryRun = false,
    ): FieldContext {
        return $this->context(
            $item,
            $blocks,
            node: 'seasons',
            options: ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
            depth: $depth,
            dryRun: $dryRun,
        );
    }

    /**
     * The block-type cards out of an extras region — the settings that pick a
     * block source lead it, so a card is never at a fixed index.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    protected function cards(array $nodes): array
    {
        return array_values(array_filter(
            $nodes,
            static fn(array $node): bool => ($node['type'] ?? null) === 'matrixFields',
        ));
    }

    /**
     * One extras node by handle.
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    protected function nodeByHandle(array $nodes, string $handle): array
    {
        foreach ($nodes as $node) {
            if (($node['handle'] ?? null) === $handle) {
                return $node;
            }
        }

        $this->fail("No schema node with handle '{$handle}'.");
    }

    /**
     * A child's mapping rows keyed by handle — the derivation emits them
     * native-first, so array_keys() over this is the row order.
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
}
