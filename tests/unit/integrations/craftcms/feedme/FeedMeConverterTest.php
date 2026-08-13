<?php

namespace GlueAgency\Influx\Tests\unit\integrations\craftcms\feedme;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\integrations\craftcms\feedme\FeedMeConversion;
use GlueAgency\Influx\integrations\craftcms\feedme\FeedMeConverter;

/**
 * Feed Me → Influx conversion spec.
 *
 * Exercises the pure translation rules (vocabulary renames, node-path
 * separators, sentinel nodes, processing flags, unique → match) against a
 * converter whose Craft lookups are stubbed — the unit suite runs without a
 * Craft boot.
 */
class FeedMeConverterTest extends Unit
{
    public function testBasicFeedConverts(): void
    {
        $conversion = $this->convert();
        $link = $conversion->link;

        $this->assertSame('News articles', $link->name);
        $this->assertSame('newsArticles', $link->handle);
        $this->assertSame('craft\elements\Entry', $link->elementType);
        $this->assertSame('https://example.test/feed.json', $link->endpoint);
        $this->assertSame(['section' => 'news', 'type' => 'article'], $link->elementCriteria);
        $this->assertSame([ProcessingAction::CREATE->value, ProcessingAction::UPDATE->value], $link->processing);
        $this->assertTrue($link->backup);
    }

    public function testNodePathsSwapSlashesForDots(): void
    {
        $link = $this->convert([
            'primaryElement' => 'data/items',
            'paginationNode' => 'meta/next',
            'fieldMapping'   => ['body' => ['node' => 'content/rendered', 'default' => '']],
        ])->link;

        $this->assertSame('data.items', $link->rootNode);
        $this->assertSame('meta.next', $link->paginatorNode);
        $this->assertSame('content.rendered', $link->mappings['body']['node']);
    }

    public function testNoImportMappingsAreDropped(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'title' => ['attribute' => 1, 'node' => 'title', 'default' => ''],
                'slug'  => ['attribute' => 1, 'node' => 'noimport', 'default' => 'kept-out'],
            ],
        ])->link;

        $this->assertArrayHasKey('title', $link->mappings);
        $this->assertArrayNotHasKey('slug', $link->mappings);
    }

    public function testUseDefaultSentinelBecomesUseDefaultFlag(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'category' => ['node' => 'usedefault', 'default' => ['12']],
            ],
        ])->link;

        $this->assertSame(
            ['useDefault' => true, 'default' => '12'],
            $link->mappings['category'],
        );
    }

    public function testNativeAttributeHandlesAreRenamed(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'enabled'  => ['attribute' => 1, 'node' => 'state', 'default' => '1'],
                'authorId' => ['attribute' => 1, 'node' => 'author/id', 'options' => ['match' => 'id']],
            ],
        ])->link;

        // `enabled` passes through unchanged — Influx maps it natively too.
        $this->assertArrayHasKey('enabled', $link->mappings);
        $this->assertArrayHasKey('author', $link->mappings);
        $this->assertSame('author.id', $link->mappings['author']['node']);
        $this->assertSame(['match' => 'id'], $link->mappings['author']['options']);
    }

    public function testABooleanNativeDefaultIsRespeltForItsSelect(): void
    {
        // Reported: an imported `enabled` row showed an empty select. Feed Me
        // stores the flag as '1' / '0'; the row is a select over 'true' / 'false'
        // (EntryTarget::nativeFieldDefinitions()), so the stored value matched no
        // option — and the empty pick an operator would then write prunes the
        // default away, leaving a useDefault mapping that disables everything.
        $link = $this->convert([
            'fieldMapping' => [
                'enabled' => ['attribute' => 1, 'node' => 'usedefault', 'default' => '1'],
            ],
        ])->link;

        $this->assertSame(['useDefault' => true, 'default' => 'true'], $link->mappings['enabled']);

        $off = $this->convert([
            'fieldMapping' => [
                'enabled' => ['attribute' => 1, 'node' => 'usedefault', 'default' => '0'],
            ],
        ])->link;

        $this->assertSame(['useDefault' => true, 'default' => 'false'], $off->mappings['enabled']);
    }

    public function testABooleanNativeKeepsItsMeaningUnderInfluxsOwnCoercion(): void
    {
        // A spelling Feed Me counted as true but Influx doesn't converts to what
        // INFLUX would have made of it, so the config means at rest exactly what
        // it would have meant at sync time. The parity warning still fires; this
        // is not a silent reinterpretation.
        $conversion = $this->convert([
            'fieldMapping' => [
                'enabled' => ['attribute' => 1, 'node' => 'usedefault', 'default' => 'active'],
            ],
        ]);

        $this->assertSame('false', $conversion->link->mappings['enabled']['default']);
        $this->assertNotEmpty(array_filter(
            $conversion->warnings,
            static fn(string $warning): bool => str_contains($warning, "'enabled' is read as a boolean"),
        ));
    }

    public function testANonNativeBooleanDefaultIsLeftAlone(): void
    {
        // A Lightswitch CUSTOM field's default cell is a plain text control, where
        // '1' already displays and round-trips — respelling it would be churn.
        $link = $this->convert([
            'fieldMapping' => [
                'is_featured' => [
                    'field'   => 'craft\fields\Lightswitch',
                    'node'    => 'usedefault',
                    'default' => '1',
                ],
            ],
        ])->link;

        $this->assertSame(['useDefault' => true, 'default' => '1'], $link->mappings['is_featured']);
    }

    public function testV6AuthorIdsHandleAlsoMapsToAuthor(): void
    {
        // Feed Me 6 (Craft 5) renamed the entry author native from
        // `authorId` to `authorIds`; rows of both vintages must convert.
        $link = $this->convert([
            'fieldMapping' => [
                'authorIds' => ['attribute' => 1, 'node' => 'author/id', 'options' => ['match' => 'id']],
            ],
        ])->link;

        $this->assertArrayHasKey('author', $link->mappings);
        $this->assertSame('author.id', $link->mappings['author']['node']);
    }

    public function testDateFormatSentinelsTranslateToInfluxFormat(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'postDate'  => ['attribute' => 1, 'node' => 'published_at', 'options' => ['match' => 'world']],
                'eventDate' => ['node' => 'starts_at', 'options' => ['match' => 'seconds']],
                'autoDate'  => ['node' => 'updated_at', 'options' => ['match' => 'auto']],
                'msDate'    => ['node' => 'created_at', 'options' => ['match' => 'milliseconds']],
            ],
        ]);
        $mappings = $conversion->link->mappings;

        $this->assertSame(['format' => 'j/n/Y'], $mappings['postDate']['options']);
        $this->assertSame(['format' => 'timestamp'], $mappings['eventDate']['options']);
        $this->assertArrayNotHasKey('options', $mappings['autoDate']);
        $this->assertArrayNotHasKey('options', $mappings['msDate']);
        $this->assertWarningMatching('/millisecond/', $conversion);
        $this->assertWarningMatching('/approximated/', $conversion);
    }

    public function testRelationMatchOptionIsNotMistakenForADateFormat(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => ['node' => 'related/slug', 'options' => ['match' => 'slug']],
            ],
        ])->link;

        $this->assertSame(['match' => 'slug'], $link->mappings['relatedEntries']['options']);
    }

    public function testLegacyColumnMatchOptionsAreNormalized(): void
    {
        // Feed Me ≤5 stored relation match values as content-table column
        // names: `field_<handle>` for custom fields, `elements.id` for id.
        $link = $this->convert([
            'fieldMapping' => [
                'relatedEntries'    => ['node' => 'related/code', 'options' => ['match' => 'field_fiona_import_id']],
                'relatedCategories' => ['node' => 'cats/id', 'options' => ['match' => 'elements.id']],
            ],
        ], knownFields: ['fiona_import_id'])->link;

        $this->assertSame(['match' => 'fiona_import_id'], $link->mappings['relatedEntries']['options']);
        $this->assertSame(['match' => 'id'], $link->mappings['relatedCategories']['options']);
    }

    public function testLegacyColumnMatchStripsCraft37ColumnSuffix(): void
    {
        // Fields created on Craft 3.7+ carry a random column suffix —
        // `field_<handle>_<suffix>` — which only strips when the result
        // resolves to a real field.
        $link = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => ['node' => 'related/code', 'options' => ['match' => 'field_importCode_lcfqejjv']],
            ],
        ], knownFields: ['importCode'])->link;

        $this->assertSame(['match' => 'importCode'], $link->mappings['relatedEntries']['options']);
    }

    public function testFieldGenuinelyNamedWithFieldPrefixSurvives(): void
    {
        // A Feed Me 6 row can hold a bare handle that happens to start with
        // `field_`; when that exact field exists the value stays untouched.
        $link = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => ['node' => 'related/code', 'options' => ['match' => 'field_custom']],
            ],
        ], knownFields: ['field_custom'])->link;

        $this->assertSame(['match' => 'field_custom'], $link->mappings['relatedEntries']['options']);
    }

    public function testUnresolvableLegacyMatchStillStripsButWarns(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => ['node' => 'related/code', 'options' => ['match' => 'field_goneField']],
            ],
        ]);

        $this->assertSame(['match' => 'goneField'], $conversion->link->mappings['relatedEntries']['options']);
        $this->assertWarningMatching('/goneField.*verify the Match by/i', $conversion);
    }

    public function testTheCreateTargetIsDroppedBecauseTheFieldAnswersIt(): void
    {
        // Feed Me stores a per-mapping create target (`group.sectionId` /
        // `group.typeId`). Influx reads the FIELD's allowed sources instead
        // (Entries::createTarget()), so keeping Feed Me's copy would store a
        // second answer to a settled question — and one the save-time prune
        // strips anyway, since no control declares it.
        $conversion = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => [
                    'node'    => 'related/slug',
                    'options' => [
                        'match'  => 'slug',
                        'create' => '1',
                        'group'  => ['sectionId' => '2', 'typeId' => '4'],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            ['match' => 'slug', 'create' => '1'],
            $conversion->link->mappings['relatedEntries']['options'],
        );

        // Not silent: it can move entries, if the feed created somewhere the
        // field doesn't list first.
        $this->assertWarningMatching('/Create-target on .relatedEntries. was dropped/', $conversion);
    }

    public function testAMappingWithoutACreateTargetIsNotWarnedAbout(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => [
                    'node'    => 'related/slug',
                    'options' => ['match' => 'slug', 'create' => '1'],
                ],
            ],
        ]);

        $this->assertSame(
            ['match' => 'slug', 'create' => '1'],
            $conversion->link->mappings['relatedEntries']['options'],
        );
        $this->assertEmpty(array_filter(
            $conversion->warnings,
            static fn(string $warning): bool => str_contains($warning, 'Create-target'),
        ));
    }

    public function testAssetOptionsTranslateToInfluxVocabulary(): void
    {
        // Feed Me: match filename|id (default filename) + upload + conflict
        // (index|replace|create). Influx: mode id|url + upload + conflict
        // (index|replace). The upload toggle's visibility keys off
        // `mode === 'url'`, so the mode translation is what keeps a
        // migrated mapping's upload behaviour alive in the builder.
        $link = $this->convert([
            'fieldMapping' => [
                'heroImage' => [
                    'field'   => 'craft\fields\Assets',
                    'node'    => 'image/url',
                    'options' => ['match' => 'filename', 'upload' => 1, 'conflict' => 'index'],
                ],
                'byId' => [
                    'field'   => 'craft\fields\Assets',
                    'node'    => 'image/id',
                    'options' => ['match' => 'id'],
                ],
            ],
        ])->link;

        $this->assertSame(
            ['upload' => 1, 'conflict' => 'index', 'mode' => 'url'],
            $link->mappings['heroImage']['options'],
        );
        // id matching is Influx's default mode — no options survive.
        $this->assertArrayNotHasKey('options', $link->mappings['byId']);
    }

    public function testAssetKeepBothAndFilenameNodeAreDroppedWithWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'heroImage' => [
                    'field'   => 'craft\fields\Assets',
                    'node'    => 'image/url',
                    'options' => ['upload' => 1, 'conflict' => 'create', 'filenameNode' => 'image/name'],
                ],
            ],
        ]);

        $this->assertSame(
            ['upload' => 1, 'mode' => 'url'],
            $conversion->link->mappings['heroImage']['options'],
        );
        $this->assertWarningMatching('/Keep both/', $conversion);
        $this->assertWarningMatching('/filenameNode/', $conversion);
    }

    public function testUnsupportedNativesAreDroppedWithWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'parent' => ['attribute' => 1, 'node' => 'parent_id', 'default' => ''],
                'id'     => ['attribute' => 1, 'node' => 'entry_id', 'default' => ''],
            ],
        ]);

        $this->assertSame([], $conversion->link->mappings);
        $this->assertWarningMatching('/parent/', $conversion);
        $this->assertWarningMatching("/'id'/", $conversion);
    }

    public function testDontImportNativesAreSkippedSilently(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'id'     => ['attribute' => 1, 'node' => 'noimport', 'default' => ''],
                'parent' => ['attribute' => 1, 'node' => 'noimport', 'default' => ''],
            ],
        ]);

        $this->assertArrayNotHasKey('id', $conversion->link->mappings);
        $this->assertArrayNotHasKey('parent', $conversion->link->mappings);
        $this->assertNoWarningMatching('/counterpart/', $conversion);
    }

    public function testUseDefaultNativesWithEmptyDefaultAreSkippedSilently(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'id' => ['attribute' => 1, 'node' => 'usedefault', 'default' => ''],
            ],
        ]);

        $this->assertArrayNotHasKey('id', $conversion->link->mappings);
        $this->assertNoWarningMatching('/counterpart/', $conversion);
    }

    public function testUseDefaultNativesWithRealDefaultAreDroppedWithWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'id' => ['attribute' => 1, 'node' => 'usedefault', 'default' => '42'],
            ],
        ]);

        $this->assertArrayNotHasKey('id', $conversion->link->mappings);
        $this->assertWarningMatching('/counterpart/', $conversion);
    }

    public function testMatrixBlocksConvertToBlocksChannel(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => ['text' => ['fields' => ['body' => ['node' => 'body']]]],
                ],
            ],
        ]);

        $this->assertSame(
            ['blocks' => ['text' => ['fields' => ['body' => ['node' => 'body']]]]],
            $conversion->link->mappings['contentBlocks'],
        );
        $this->assertNoWarningMatching('/Matrix/', $conversion);
    }

    /**
     * One block type reading a flat list: the list node moves onto the Matrix
     * row and the child path is rebased onto a list item, so the blocks keep
     * the order Feed Me gave them.
     */
    public function testMatrixBlockChildNodePathsSwapSlashesForDots(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => [
                        'quote' => ['fields' => ['text' => ['node' => 'quotes/text', 'default' => '']]],
                    ],
                ],
            ],
        ])->link;

        $this->assertSame(
            [
                'node'    => 'quotes',
                'options' => ['blockSource' => 'listSingle'],
                'blocks'  => ['quote' => ['fields' => ['text' => ['node' => 'text']]]],
            ],
            $link->mappings['contentBlocks'],
        );
    }

    /**
     * Feed Me sorts blocks on the array index in each node path, so its output
     * follows the feed rather than the field's type order. The wrapper shape —
     * each type's children sharing one segment under the list node — converts to
     * the source that reads it the same way.
     */
    public function testMatrixBlocksUnderPerTypeKeysConvertToTheKeyedListSource(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => [
                        'text' => ['fields' => [
                            'body'  => ['node' => 'content_blocks/text/text'],
                            'image' => ['node' => 'content_blocks/text/image'],
                        ]],
                        'quote' => ['fields' => [
                            'quote' => ['node' => 'content_blocks/quote/quote'],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            [
                'node' => 'content_blocks',
                // Every type's key is written, including one that equals its
                // handle: a keyed source claims a type only by a declared key.
                'options' => [
                    'blockSource'     => 'listByKey',
                    'sourceKey_text'  => 'text',
                    'sourceKey_quote' => 'quote',
                ],
                'blocks' => [
                    'text' => ['fields' => [
                        'body'  => ['node' => 'text'],
                        'image' => ['node' => 'image'],
                    ]],
                    'quote' => ['fields' => ['quote' => ['node' => 'quote']]],
                ],
            ],
            $conversion->link->mappings['contentBlocks'],
        );
        $this->assertNoWarningMatching('/Matrix/', $conversion);
    }

    /**
     * A feed key that isn't the Craft handle rides along as the type's alias
     * rather than forcing the operator to rename the block type.
     */
    public function testAFeedKeyUnlikeTheHandleBecomesAnAlias(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => [
                        'text' => ['fields' => ['body' => ['node' => 'content_blocks/textBlock/text']]],
                    ],
                ],
            ],
        ])->link;

        $this->assertSame(
            ['blockSource' => 'listByKey', 'sourceKey_text' => 'textBlock'],
            $link->mappings['contentBlocks']['options'],
        );
    }

    /**
     * The shape Feed Me itself can't resolve: several types whose children sit
     * flat under one list, where its own path matching attributes a shared child
     * handle to whichever type was configured first. Influx won't inherit that
     * guess — it stays grouped and says so.
     */
    public function testSeveralFlatBlockTypesStayGroupedWithAWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => [
                        'text'  => ['fields' => ['body' => ['node' => 'content_blocks/body']]],
                        'quote' => ['fields' => ['quote' => ['node' => 'content_blocks/quote']]],
                    ],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('node', $conversion->link->mappings['contentBlocks']);
        $this->assertArrayNotHasKey('options', $conversion->link->mappings['contentBlocks']);
        $this->assertWarningMatching('/no way to tell an item/', $conversion);
    }

    public function testBlockTypesReadingDifferentNodesStayGroupedWithAWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => [
                        'text'  => ['fields' => ['body' => ['node' => 'paragraphs/text']]],
                        'quote' => ['fields' => ['quote' => ['node' => 'quotes/text']]],
                    ],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('node', $conversion->link->mappings['contentBlocks']);
        $this->assertWarningMatching('/different feed nodes/', $conversion);
    }

    public function testMatrixBlockWithOnlyNoImportChildIsOmitted(): void
    {
        // A block type whose only child is "don't import" carries nothing, so
        // that type — and, since it's the only one, the whole Matrix mapping —
        // is omitted, like any other no-op mapping.
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => ['text' => ['fields' => ['body' => ['node' => 'noimport', 'default' => '']]]],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('contentBlocks', $conversion->link->mappings);
        $this->assertNoWarningMatching('/Matrix/', $conversion);
    }

    public function testRelatedElementSubFieldsRecurse(): void
    {
        $link = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => [
                    'node'    => 'related/slug',
                    'options' => ['match' => 'slug', 'create' => '1'],
                    'fields'  => [
                        'summary' => ['node' => 'related/summary', 'default' => ''],
                        'ignored' => ['node' => 'noimport', 'default' => ''],
                    ],
                ],
            ],
        ])->link;

        $mapping = $link->mappings['relatedEntries'];
        $this->assertSame('related.slug', $mapping['node']);
        $this->assertSame(['match' => 'slug', 'create' => '1'], $mapping['options']);
        $this->assertSame(['summary' => ['node' => 'related.summary']], $mapping['fields']);
    }

    public function testProcessingFlagsTranslate(): void
    {
        // Feed Me's whole vocabulary, each with a direct counterpart — including
        // `disableForSite`, which used to be flattened onto the global `disable`.
        // Whether the per-site half survives is the save's call, against the link's
        // endpoint shape; the conversion no longer pre-empts it.
        $conversion = $this->convert([
            'duplicateHandle' => ['add', 'update', 'disable', 'disableForSite', 'delete'],
        ]);

        $this->assertSame(
            [
                ProcessingAction::CREATE->value,
                ProcessingAction::UPDATE->value,
                ProcessingAction::DISABLE->value,
                ProcessingAction::DISABLE_FOR_SITE->value,
                ProcessingAction::DELETE->value,
            ],
            $conversion->link->processing,
        );
    }

    public function testAnUnknownProcessingFlagWarnsAndIsDropped(): void
    {
        $conversion = $this->convert(['duplicateHandle' => ['add', 'deleteForSite']]);

        $this->assertSame([ProcessingAction::CREATE->value], $conversion->link->processing);
        $this->assertWarningMatching('/deleteForSite/', $conversion);
    }

    public function testABooleanTargetWarnsAboutTheNarrowerTruthyVocabulary(): void
    {
        // A runtime divergence rather than a config one: Feed Me counts 'active' /
        // 'live' / 'y' as true, Influx reads them as false. Nothing in the link can
        // fix it, so the conversion has to say so per affected row.
        $conversion = $this->convert([
            'fieldMapping' => [
                'showBanner' => ['field' => 'craft\fields\Lightswitch', 'node' => 'visible'],
                'specs'      => ['field' => 'craft\fields\Table', 'node' => 'specs'],
                'enabled'    => ['attribute' => 1, 'node' => 'is_live'],
                'importId'   => ['node' => 'external_id'],
            ],
        ]);

        $this->assertWarningMatching("/'showBanner' is read as a boolean/", $conversion);
        $this->assertWarningMatching("/'specs' is read as a boolean/", $conversion);
        $this->assertWarningMatching("/'enabled' is read as a boolean/", $conversion);
        $this->assertNoWarningMatching("/'importId' is read as a boolean/", $conversion);
    }

    public function testAPlainFieldNeverWarnsAboutBooleans(): void
    {
        $this->assertNoWarningMatching('/read as a boolean/', $this->convert());
    }

    public function testANonNativeHandleNamedEnabledIsNotTreatedAsTheNativeFlag(): void
    {
        // Only the ATTRIBUTE `enabled` goes through the boolean coercion; a custom
        // field that happens to share the handle is whatever its own type says.
        $conversion = $this->convert([
            'fieldMapping' => ['enabled' => ['field' => 'craft\fields\PlainText', 'node' => 'state']],
        ]);

        $this->assertNoWarningMatching('/read as a boolean/', $conversion);
    }

    public function testFirstUsableUniqueBecomesTheMatchAttribute(): void
    {
        $conversion = $this->convert([
            'fieldUnique'  => ['id' => '', 'importId' => 1, 'title' => 1],
            'fieldMapping' => [
                'importId' => ['node' => 'external_id', 'default' => ''],
                'title'    => ['attribute' => 1, 'node' => 'title', 'default' => ''],
            ],
        ]);

        $this->assertSame(['attribute' => 'importId'], $conversion->link->match);
        $this->assertWarningMatching('/single attribute.*title/', $conversion);
    }

    public function testUnmatchableUniqueWarnsAndLeavesMatchEmpty(): void
    {
        $conversion = $this->convert(['fieldUnique' => ['id' => 1]]);

        $this->assertSame([], $conversion->link->match);
        $this->assertWarningMatching('/match attribute/', $conversion);
    }

    public function testMultiSiteFeedBecomesSiteEndpoint(): void
    {
        $link = $this->convert(['siteId' => '2'], multiSite: true)->link;

        $this->assertNull($link->endpoint);
        $this->assertSame([['site' => 'nl', 'endpoint' => 'https://example.test/feed.json']], $link->siteEndpoints);
    }

    public function testSingleSiteFeedKeepsDefaultEndpointDespiteSiteId(): void
    {
        $link = $this->convert(['siteId' => '1'], multiSite: false)->link;

        $this->assertSame('https://example.test/feed.json', $link->endpoint);
        $this->assertSame([], $link->siteEndpoints);
    }

    public function testNonJsonFeedWarns(): void
    {
        $conversion = $this->convert(['feedType' => 'xml']);
        $this->assertWarningMatching('/JSON/', $conversion);
    }

    public function testJsonColumnsDecodeFromRawDbStrings(): void
    {
        $link = $this->convert([
            'elementGroup'    => json_encode(['craft\elements\Entry' => ['section' => 2, 'entryType' => 4]]),
            'duplicateHandle' => json_encode(['add']),
            'fieldMapping'    => json_encode(['title' => ['attribute' => 1, 'node' => 'title', 'default' => '']]),
            'fieldUnique'     => json_encode(['title' => 1]),
        ])->link;

        $this->assertSame(['section' => 'news', 'type' => 'article'], $link->elementCriteria);
        $this->assertSame([ProcessingAction::CREATE->value], $link->processing);
        $this->assertSame(['node' => 'title'], $link->mappings['title']);
        $this->assertSame(['attribute' => 'title'], $link->match);
    }

    public function testUnnameableFeedFallsBackToIdHandle(): void
    {
        $link = $this->convert(['name' => '!!!', 'id' => 7])->link;
        $this->assertSame('feed7', $link->handle);
    }

    /**
     * Run a feed row (sane defaults, overridable) through a converter with
     * stubbed Craft lookups.
     */
    /**
     * The converter spells the Matrix feed-key option itself rather than
     * reaching into the field strategy — the integration speaking the core
     * vocabulary, not the core carrying an API for one importer. This is what
     * keeps the two spellings honest: the handle comes off the schema Matrix
     * actually emits, so a rename there fails here rather than silently
     * converting links whose block types nothing claims.
     */
    public function testTheConvertersFeedKeyOptionMatchesTheSchemasOwn(): void
    {
        $declared = (new class() extends Matrix {
            /** @return list<array<string, mixed>> */
            public function exposedSettings(array $blockType): array
            {
                return $this->blockTypeSettings($blockType);
            }
        })->exposedSettings(['handle' => 'text', 'name' => 'Text', 'layout' => null, 'hasTitleField' => false]);

        $converterKey = (new class() extends FeedMeConverter {
            public function exposedOption(string $typeHandle): string
            {
                return $this->matrixSourceKeyOption($typeHandle);
            }
        })->exposedOption('text');

        $this->assertSame($declared[0]['handle'], $converterKey);
    }

    protected function convert(array $overrides = [], bool $multiSite = false, array $knownFields = []): FeedMeConversion
    {
        $feed = array_merge([
            'id'              => 1,
            'name'            => 'News articles',
            'feedUrl'         => 'https://example.test/feed.json',
            'feedType'        => 'json',
            'primaryElement'  => null,
            'elementType'     => 'craft\elements\Entry',
            'elementGroup'    => ['craft\elements\Entry' => ['section' => 2, 'entryType' => 4]],
            'siteId'          => '',
            'singleton'       => 0,
            'duplicateHandle' => ['add', 'update'],
            'paginationNode'  => null,
            'fieldMapping'    => [
                'title'    => ['attribute' => 1, 'node' => 'title', 'default' => ''],
                'importId' => ['node' => 'external_id', 'default' => ''],
            ],
            'fieldUnique'    => ['importId' => 1],
            'backup'         => 1,
            'setEmptyValues' => 0,
        ], $overrides);

        return $this->converter($multiSite, $knownFields)->convert($feed);
    }

    protected function converter(bool $multiSite, array $knownFields = []): FeedMeConverter
    {
        return new class($multiSite, $knownFields) extends FeedMeConverter {
            protected bool $multiSite = false;

            /** @var string[] field handles that "exist" in the stub install */
            protected array $knownFields = [];

            public function __construct(bool $multiSite, array $knownFields)
            {
                $this->multiSite = $multiSite;
                $this->knownFields = $knownFields;
            }

            protected function fieldExistsByHandle(string $handle): bool
            {
                return in_array($handle, $this->knownFields, true);
            }

            protected function handleFromName(string $name): string
            {
                // Naive stand-in for StringHelper::toHandle, which needs a
                // booted Craft app for transliteration.
                $handle = strtolower(preg_replace('/[^a-zA-Z0-9]+/', ' ', $name));
                $handle = preg_replace('/^[^a-z]+/', '', $handle);
                $words = array_values(array_filter(explode(' ', $handle)));
                $first = array_shift($words) ?? '';

                return $first . implode('', array_map('ucfirst', $words));
            }

            protected function isMultiSite(): bool
            {
                return $this->multiSite;
            }

            protected function siteHandleById(int $id): ?string
            {
                return [1 => 'default', 2 => 'nl'][$id] ?? null;
            }

            protected function sectionHandleById(int $id): ?string
            {
                return $id === 2 ? 'news' : null;
            }

            protected function entryTypeHandleById(int $id): ?string
            {
                return $id === 4 ? 'article' : null;
            }
        };
    }

    protected function assertWarningMatching(string $pattern, FeedMeConversion $conversion): void
    {
        foreach ($conversion->warnings as $warning) {
            if (preg_match($pattern, $warning)) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail("No warning matching {$pattern}. Got:\n- " . implode("\n- ", $conversion->warnings));
    }

    protected function assertNoWarningMatching(string $pattern, FeedMeConversion $conversion): void
    {
        foreach ($conversion->warnings as $warning) {
            if (preg_match($pattern, $warning)) {
                $this->fail("Unexpected warning matching {$pattern}: {$warning}");
            }
        }

        $this->assertTrue(true);
    }
}
