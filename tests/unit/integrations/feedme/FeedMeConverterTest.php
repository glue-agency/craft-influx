<?php

namespace GlueAgency\Influx\Tests\unit\integrations\feedme;

use Codeception\Test\Unit;
use GlueAgency\Influx\integrations\feedme\FeedMeConversion;
use GlueAgency\Influx\integrations\feedme\FeedMeConverter;
use GlueAgency\Influx\models\Link;

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
        $this->assertSame([Link::PROCESSING_CREATE, Link::PROCESSING_UPDATE], $link->processing);
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

    public function testCreateGroupIdsTranslateToHandles(): void
    {
        // Feed Me's create-target (`group.sectionId` / `group.typeId`)
        // carries raw DB ids — environment-specific, so the YAML-stable
        // form is handles. The stub resolves section 2 → 'news' and entry
        // type 4 → 'article'.
        $link = $this->convert([
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
        ])->link;

        $this->assertSame(
            ['match' => 'slug', 'create' => '1', 'group' => ['section' => 'news', 'type' => 'article']],
            $link->mappings['relatedEntries']['options'],
        );
    }

    public function testUnresolvableCreateGroupIdsAreDroppedWithWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'relatedEntries' => [
                    'node'    => 'related/slug',
                    'options' => ['create' => '1', 'group' => ['sectionId' => '99', 'typeId' => '77']],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('group', $conversion->link->mappings['relatedEntries']['options']);
        $this->assertWarningMatching('/section id 99/', $conversion);
        $this->assertWarningMatching('/entry type id 77/', $conversion);
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

    public function testMatrixBlocksAreDroppedWithWarning(): void
    {
        $conversion = $this->convert([
            'fieldMapping' => [
                'contentBlocks' => [
                    'blocks' => ['text' => ['fields' => ['body' => ['node' => 'body']]]],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('contentBlocks', $conversion->link->mappings);
        $this->assertWarningMatching('/Matrix/', $conversion);
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
        $conversion = $this->convert([
            'duplicateHandle' => ['add', 'update', 'disable', 'delete', 'disableForSite'],
        ]);

        $this->assertSame(
            [
                Link::PROCESSING_CREATE,
                Link::PROCESSING_UPDATE,
                Link::PROCESSING_DISABLE,
                Link::PROCESSING_DELETE,
            ],
            $conversion->link->processing,
        );
        $this->assertWarningMatching('/disable/i', $conversion);
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
        $this->assertSame(['nl' => 'https://example.test/feed.json'], $link->siteEndpoints);
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
        $this->assertSame([Link::PROCESSING_CREATE], $link->processing);
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
}
