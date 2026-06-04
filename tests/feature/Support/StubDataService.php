<?php

namespace TDM\Influx\Tests\feature\Support;

use TDM\Influx\models\Link;
use TDM\Influx\services\DataService;

/**
 * Test double for {@see DataService}. SyncService talks to the real service
 * via the plugin's `data` component; we swap this stub in via
 * `Influx::getInstance()->set('data', $stub)` in the test setUp.
 *
 * Each test queues responses with {@see queueFetch} (root-list fetches) or
 * {@see queueFetchOne} (per-item fetches). Stubs return them in FIFO order.
 *
 * `paginatorNode` is honoured: a queued response whose 'next' URL points at
 * a follow-up means the sync engine will paginate. Use {@see queueFetchUrl}
 * to queue page-N responses.
 */
class StubDataService extends DataService
{
    /** @var array<int, array> */
    public array $fetchQueue = [];

    /** @var array<string, array> keyed by URL */
    public array $urlQueue = [];

    /** @var array<int, array> */
    public array $fetchOneQueue = [];

    /** @var list<array{method:string, args:array}> recorded for assertions */
    public array $calls = [];

    public function init(): void
    {
        // skip parent — no real HTTP client
    }

    public function queueFetch(array $response): void
    {
        $this->fetchQueue[] = $response;
    }

    public function queueFetchOne(array $response): void
    {
        $this->fetchOneQueue[] = $response;
    }

    public function queueFetchUrl(string $url, array $response): void
    {
        $this->urlQueue[$url] = $response;
    }

    public function fetch(Link $link, ?string $siteHandle = null, array $queryParams = []): array
    {
        $this->calls[] = ['method' => 'fetch', 'args' => compact('siteHandle', 'queryParams')];
        return array_shift($this->fetchQueue) ?? [];
    }

    public function fetchOne(Link $link, array $tokens, ?string $siteHandle = null): array
    {
        $this->calls[] = ['method' => 'fetchOne', 'args' => compact('tokens', 'siteHandle')];
        return array_shift($this->fetchOneQueue) ?? [];
    }

    public function fetchUrl(string $url, array $headers = [], array $query = []): array
    {
        $this->calls[] = ['method' => 'fetchUrl', 'args' => ['url' => $url]];
        return $this->urlQueue[$url] ?? [];
    }
}
