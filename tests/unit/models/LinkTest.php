<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\elements\Entry;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * Link model behaviour spec — the bits the sync engine relies on.
 *
 * The Link is the source of truth for one sync configuration; these tests
 * lock in the shape of `matchValue()`, `matchAttribute()`, `siteHandles()`
 * and `getConfig()` because every other service reads off them.
 */
class LinkTest extends Unit
{
    public function testMatchAttributeIsReadFromMatchConfig(): void
    {
        $link = $this->link(['match' => ['attribute' => 'importId']]);
        $this->assertSame('importId', $link->matchAttribute());
    }

    public function testMatchValueIsPulledFromTheConfiguredNode(): void
    {
        $link = $this->link([
            'match'    => ['attribute' => 'importId'],
            'mappings' => ['importId' => ['node' => 'remote_id']],
        ]);
        $this->assertSame(42, $link->matchValue(new RemoteItem(['remote_id' => 42, 'title' => 'x'])));
    }

    public function testMatchValueSupportsNestedNodes(): void
    {
        $link = $this->link([
            'match'    => ['attribute' => 'importId'],
            'mappings' => ['importId' => ['node' => 'meta.remote_id']],
        ]);
        $this->assertSame('abc', $link->matchValue(new RemoteItem(['meta' => ['remote_id' => 'abc']])));
    }

    public function testMatchValueIsNullWhenSourceMissing(): void
    {
        $link = $this->link([
            'match'    => ['attribute' => 'importId'],
            'mappings' => ['importId' => ['node' => 'remote_id']],
        ]);
        $this->assertNull($link->matchValue(new RemoteItem(['title' => 'x'])));
    }

    public function testSiteHandlesReturnsKeysOfSiteEndpoints(): void
    {
        $link = $this->link([
            'siteEndpoints' => ['default' => 'https://e/en', 'nl' => 'https://e/nl'],
        ]);
        $this->assertSame(['default', 'nl'], $link->siteHandles());
    }

    public function testSiteHandlesEmptyWhenNoneConfigured(): void
    {
        $this->assertSame([], $this->link()->siteHandles());
    }

    public function testGetConfigStripsEmptyKeysSoYAMLStaysReadable(): void
    {
        $link = $this->link([
            'handle'      => 'articles',
            'name'        => 'Articles',
            'elementType' => 'craft\elements\Entry',
            'endpoint'    => 'https://e/articles',
            'mappings'    => ['title' => ['node' => 'name']],
            'match'       => ['attribute' => 'importId'],
        ]);

        $config = $link->getConfig();

        $this->assertArrayHasKey('mappings', $config);
        $this->assertArrayHasKey('match', $config);
        // Empty arrays / nulls / "" / false should be dropped from project config:
        $this->assertArrayNotHasKey('siteEndpoints', $config);
        $this->assertArrayNotHasKey('itemEndpoint', $config);
        $this->assertArrayNotHasKey('offset', $config);
        $this->assertArrayNotHasKey('paginatorNode', $config);
        $this->assertArrayNotHasKey('backup', $config);
    }

    public function testProcessingDefaultsToCreateAndUpdate(): void
    {
        $this->assertSame(['create', 'update'], $this->link()->processing);
    }

    public function testEnsureUidIsIdempotent(): void
    {
        $link = $this->link();
        $link->ensureUid();
        $first = $link->uid;
        $this->assertNotEmpty($first);

        $link->ensureUid();
        $this->assertSame($first, $link->uid, 'Subsequent calls must not regenerate the UID.');
    }

    private function link(array $overrides = []): Link
    {
        $link = new Link();
        $link->handle = $overrides['handle'] ?? 'articles';
        $link->name = $overrides['name'] ?? 'Articles';
        $link->elementType = $overrides['elementType'] ?? Entry::class;
        $link->endpoint = $overrides['endpoint'] ?? 'https://example.test';
        $link->match = $overrides['match'] ?? ['attribute' => 'importId'];
        $link->mappings = $overrides['mappings'] ?? ['importId' => ['node' => 'id']];

        if (isset($overrides['siteEndpoints'])) {
            $link->siteEndpoints = $overrides['siteEndpoints'];
        }

        if (isset($overrides['processing'])) {
            $link->processing = $overrides['processing'];
        }

        return $link;
    }
}
