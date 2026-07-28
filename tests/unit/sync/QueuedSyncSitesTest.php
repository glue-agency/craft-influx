<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Spec for the scopes a queued CP trigger fans out to —
 * {@see SynchronizationService::sitesToQueue()}.
 *
 * The regression this pins: the expansion used to be re-derived as
 * `count($link->siteHandles()) > 1 ? $handles : [$site]`, so a link with exactly
 * ONE site endpoint queued the unscoped `[null]` scope — fetching the base
 * endpoint (legitimately absent once site endpoints exist) and sweeping missing
 * elements cross-site. {@see Link::syncSiteHandles()} owns the rule; this method
 * must only add the "explicit site wins" case on top of it.
 *
 * No Craft boot: the method reads only the link's site endpoints. The method is
 * protected, so an anonymous subclass exposes it (same seam the rest of the
 * suite favours over reflection).
 */
class QueuedSyncSitesTest extends Unit
{
    public function testExplicitSiteWinsOverTheConfiguredSites(): void
    {
        $this->assertQueues([['site' => 'nl', 'endpoint' => 'https://example.test/nl']], 'nl', ['nl']);
        $this->assertQueues(
            [
                ['site' => 'nl', 'endpoint' => 'https://example.test/nl'],
                ['site' => 'fr', 'endpoint' => 'https://example.test/fr'],
            ],
            'fr',
            ['fr'],
        );
    }

    public function testNoSiteExpandsToEveryConfiguredSite(): void
    {
        $this->assertQueues(
            [
                ['site' => 'nl', 'endpoint' => 'https://example.test/nl'],
                ['site' => 'fr', 'endpoint' => 'https://example.test/fr'],
                ['site' => 'en', 'endpoint' => 'https://example.test/en'],
            ],
            null,
            ['nl', 'fr', 'en'],
        );
    }

    public function testNoSiteOnASingleSiteLinkQueuesThatSite(): void
    {
        $this->assertQueues([['site' => 'nl', 'endpoint' => 'https://example.test/nl']], null, ['nl']);
    }

    public function testNoSiteOnASitelessLinkQueuesThePrimaryScope(): void
    {
        $this->assertQueues([], null, [null]);
    }

    /**
     * @param list<array{site: string, endpoint: string}> $siteEndpoints
     * @param list<string|null> $expected
     */
    private function assertQueues(array $siteEndpoints, ?string $site, array $expected): void
    {
        $link = FakeLink::make(['siteEndpoints' => $siteEndpoints]);

        $service = new class() extends SynchronizationService {
            public function publicSitesToQueue(Link $link, ?string $site): array
            {
                return $this->sitesToQueue($link, $site);
            }
        };

        $this->assertSame($expected, $service->publicSitesToQueue($link, $site));
    }
}
