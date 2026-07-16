<?php

namespace GlueAgency\Influx\Tests\unit\data;

use Codeception\Test\Unit;
use GlueAgency\Influx\data\EndpointResolver;
use GlueAgency\Influx\data\PagedFeed;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Pagination auth routing. The first page and any same-origin next-page URL are
 * fetched WITH the link's auth (mirrors Feed Me, which re-applies its request
 * options per page) — the regression for the live failure where page 2+ went out
 * unauthenticated and the protected API dropped the request. A next-page URL on a
 * DIFFERENT origin is fetched WITHOUT credentials, so the link's token never
 * leaks to a third-party host a feed happened to link to.
 *
 * A recording DataService double keeps this a pure unit test: endpoints() is
 * stubbed (so no Craft env resolution) and no paginatorNode is set (so nextUrl()
 * short-circuits before Craft::getAlias()).
 */
class PagedFeedTest extends Unit
{
    public function testAuthIsReappliedOnSameOriginPagesAndWithheldCrossOrigin(): void
    {
        $data = new class() extends DataService {
            /** @var list<array{0: string, 1: ?string}> */
            public array $calls = [];

            public function init(): void
            {
                // Skip the real Guzzle/endpoint setup — this double never requests.
            }

            public function endpoints(): EndpointResolver
            {
                return new class() extends EndpointResolver {
                    public function listUrlForDisplay(Link $link, ?string $siteHandle = null, array $queryParams = []): ?string
                    {
                        return 'https://example.test/articles';
                    }
                };
            }

            public function fetch(Link $link, ?string $siteHandle = null, array $queryParams = []): array
            {
                $this->calls[] = ['fetch', null];

                return [];
            }

            public function fetchForLink(Link $link, string $url, array $queryParams = []): array
            {
                $this->calls[] = ['fetchForLink', $url];

                return [];
            }

            public function fetchUrl(string $url, array $headers = [], array $query = []): array
            {
                $this->calls[] = ['fetchUrl', $url];

                return [];
            }

            public function rootList(Link $link, array $response): array
            {
                return [];
            }
        };

        $feed = new PagedFeed($data, FakeLink::make());

        $feed->page(null, 1);
        $feed->page('https://example.test/articles?page=2', 2);
        $feed->page('https://evil.test/steal?page=3', 3);

        $this->assertSame([
            ['fetch', null],
            ['fetchForLink', 'https://example.test/articles?page=2'],
            ['fetchUrl', 'https://evil.test/steal?page=3'],
        ], $data->calls);
    }
}
