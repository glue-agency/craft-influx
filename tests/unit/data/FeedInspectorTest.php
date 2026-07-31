<?php

namespace GlueAgency\Influx\Tests\unit\data;

use Codeception\Test\Unit;
use craft\elements\Entry;
use GlueAgency\Influx\data\FeedInspector;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\RemoteItem;
use ReflectionClass;

/**
 * Two specs in one, both about what the "Fetch sample" report offers the user.
 *
 * `flattenLeafPaths()` — the source of the mapping dropdowns' flatNodes. Locks
 * in the nested-shape rules: object children contribute leaves only, list
 * children are nodes themselves AND (when they hold objects) expose their first
 * element's leaves under the parent key with the index collapsed away —
 * `RemoteItem::get()` fans those reads out over every list element at sync time.
 *
 * `report()` — and specifically its partial variant: a response no list of
 * items resolves from still reports its candidates plus a warning, because
 * those candidates are the only way the user can pick the root node that fixes
 * it. Throwing there would strand a new link against an enveloped feed.
 */
class FeedInspectorTest extends Unit
{
    public function testScalarAndObjectLeaves(): void
    {
        $paths = $this->flatten([
            'id'    => 1,
            'title' => 'x',
            'meta'  => ['key' => 'a', 'inner' => ['deep' => 'b']],
        ]);

        $this->assertSame(['id', 'title', 'meta.key', 'meta.inner.deep'], $paths);
    }

    public function testListsOfObjectsCollapseTheIndexAway(): void
    {
        $item = [
            'directors' => [
                ['full_name' => 'Hans', 'role' => ['key' => 'Director', 'text' => 'Regisseur']],
                ['full_name' => 'Elke', 'role' => ['key' => 'Director', 'text' => 'Regisseur']],
            ],
            'sections' => [
                ['id' => 'abc', 'name' => 'Kortfilms'],
            ],
        ];

        $paths = $this->flatten($item);

        // The lists themselves stay nodes (whole-array mappings)…
        $this->assertContains('directors', $paths);
        $this->assertContains('sections', $paths);
        // …and element leaves sit under the parent key, index-free —
        // identical shape whether the list holds one object or many.
        $this->assertContains('directors.full_name', $paths);
        $this->assertContains('directors.role.key', $paths);
        $this->assertContains('sections.name', $paths);
        $this->assertNotContains('directors.0.full_name', $paths);

        // Multi-element lists read as every element's value…
        $remoteItem = new RemoteItem($item);
        $this->assertSame(['Hans', 'Elke'], $remoteItem->get('directors.full_name'));
        $this->assertSame(['Director', 'Director'], $remoteItem->get('directors.role.key'));
        // …single-element lists stay scalar…
        $this->assertSame('Kortfilms', $remoteItem->get('sections.name'));
        // …and explicit indices still address one element.
        $this->assertSame('Elke', $remoteItem->get('directors.1.full_name'));
    }

    public function testEmptyAndScalarListsStayPlainNodes(): void
    {
        $paths = $this->flatten([
            'tags'   => [],
            'genres' => ['a', 'b'],
        ]);

        $this->assertSame(['tags', 'genres'], $paths);
    }

    public function testAnEnvelopedResponseWithoutARootNodeReportsCandidatesAndAWarning(): void
    {
        $report = (new FeedInspector())->report(
            $this->link(),
            ['data' => [['id' => 1, 'title' => 'x']], 'meta' => ['total' => 5]],
            'https://example.test/feed',
        );

        // The remedy is in the report: 'data' is pickable as the root node.
        $this->assertContains('data', $report['rootNodeCandidates']);
        $this->assertNotNull($report['warning']);
        // Nothing item-derived can be computed without a list…
        $this->assertNull($report['sampleItem']);
        $this->assertSame([], $report['flatNodes']);
        $this->assertSame([], $report['mappingSuggestions']);
        // …but the response-level walks are unaffected.
        $this->assertContains('meta.total', $report['countNodeCandidates']);
    }

    public function testARootNodeThatDoesNotResolveDegradesTheSameWay(): void
    {
        $report = (new FeedInspector())->report(
            $this->link('items'),
            ['data' => [['id' => 1]]],
            'https://example.test/feed',
        );

        $this->assertStringContainsString('items', (string) $report['warning']);
        // A wrong pick has to stay re-pickable, so the candidates survive it.
        $this->assertContains('data', $report['rootNodeCandidates']);
        $this->assertNull($report['sampleItem']);
    }

    public function testAResolvingRootNodeReportsAFullSample(): void
    {
        $report = (new FeedInspector())->report(
            $this->link('data'),
            ['data' => [['id' => 1, 'title' => 'x']]],
            'https://example.test/feed',
        );

        $this->assertNull($report['warning']);
        $this->assertSame(['id' => 1, 'title' => 'x'], $report['sampleItem']);
        $this->assertSame(['id', 'title'], array_column($report['flatNodes'], 'value'));
    }

    public function testATopLevelListNeedsNoRootNode(): void
    {
        $report = (new FeedInspector())->report(
            $this->link(),
            [['id' => 1, 'title' => 'x']],
            'https://example.test/feed',
        );

        $this->assertNull($report['warning']);
        $this->assertSame(['id' => 1, 'title' => 'x'], $report['sampleItem']);
    }

    protected function link(?string $rootNode = null): Link
    {
        $link = new Link();
        $link->handle = 'articles';
        $link->name = 'Articles';
        $link->elementType = Entry::class;
        $link->endpoint = 'https://example.test/feed';
        $link->rootNode = $rootNode;

        return $link;
    }

    /**
     * @param array $item
     * @return list<string>
     */
    protected function flatten(array $item): array
    {
        $ref = new ReflectionClass(FeedInspector::class);
        $inspector = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('flattenLeafPaths');

        return $method->invoke($inspector, $item, []);
    }
}
