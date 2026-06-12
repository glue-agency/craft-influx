<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\sync\RemoteItem;
use ReflectionClass;

/**
 * Node-discovery spec for `DataService::flattenLeafPaths()` — the source of
 * the mapping dropdowns' flatNodes. Locks in the nested-shape rules: object
 * children contribute leaves only, list children are nodes themselves AND
 * (when they hold objects) expose their first element's leaves under the
 * parent key with the index collapsed away — `RemoteItem::get()` fans those
 * reads out over every list element at sync time.
 */
class DataServiceFlattenTest extends Unit
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

    /**
     * @param array $item
     * @return list<string>
     */
    protected function flatten(array $item): array
    {
        $ref = new ReflectionClass(DataService::class);
        $service = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('flattenLeafPaths');

        return $method->invoke($service, $item, []);
    }
}
