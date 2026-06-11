<?php

namespace TDM\Influx\Tests\unit\integrations\feedme;

use Codeception\Test\Unit;
use TDM\Influx\integrations\feedme\FeedMeConversion;
use TDM\Influx\integrations\feedme\FeedMeConverter;
use TDM\Influx\models\Link;

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
            'fieldMapping' => ['body' => ['node' => 'content/rendered', 'default' => '']],
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

        $this->assertArrayHasKey('status', $link->mappings);
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
                    'node' => 'related/slug',
                    'options' => ['match' => 'slug', 'create' => '1'],
                    'fields' => [
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
            'fieldUnique' => ['id' => '', 'importId' => 1, 'title' => 1],
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
            'elementGroup' => json_encode(['craft\elements\Entry' => ['section' => 2, 'entryType' => 4]]),
            'duplicateHandle' => json_encode(['add']),
            'fieldMapping' => json_encode(['title' => ['attribute' => 1, 'node' => 'title', 'default' => '']]),
            'fieldUnique' => json_encode(['title' => 1]),
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
    protected function convert(array $overrides = [], bool $multiSite = false): FeedMeConversion
    {
        $feed = array_merge([
            'id' => 1,
            'name' => 'News articles',
            'feedUrl' => 'https://example.test/feed.json',
            'feedType' => 'json',
            'primaryElement' => null,
            'elementType' => 'craft\elements\Entry',
            'elementGroup' => ['craft\elements\Entry' => ['section' => 2, 'entryType' => 4]],
            'siteId' => '',
            'singleton' => 0,
            'duplicateHandle' => ['add', 'update'],
            'paginationNode' => null,
            'fieldMapping' => [
                'title'    => ['attribute' => 1, 'node' => 'title', 'default' => ''],
                'importId' => ['node' => 'external_id', 'default' => ''],
            ],
            'fieldUnique' => ['importId' => 1],
            'backup' => 1,
            'setEmptyValues' => 0,
        ], $overrides);

        return $this->converter($multiSite)->convert($feed);
    }

    protected function converter(bool $multiSite): FeedMeConverter
    {
        return new class($multiSite) extends FeedMeConverter {
            protected bool $multiSite = false;

            public function __construct(bool $multiSite)
            {
                $this->multiSite = $multiSite;
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
