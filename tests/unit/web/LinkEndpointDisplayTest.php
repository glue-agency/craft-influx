<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\web\LinkPresenter;

/**
 * The log viewer's "Endpoint" row: exactly one of the two shapes is ever
 * populated — a single `endpointUrl`, or a per-site `endpoints` list — so the
 * viewer never has to guess which to render, and an all-sites run on a link with
 * per-site endpoints never claims a base URL that was never fetched.
 *
 * Pure Link + run primitives, so it specs without a booted Craft or a log record.
 */
class LinkEndpointDisplayTest extends Unit
{
    public function testADeletedLinkPopulatesNeitherShape(): void
    {
        $this->assertSame(
            ['endpointUrl' => null, 'endpoints' => null],
            (new LinkPresenter())->endpointDisplay(null, 7, 'nl'),
        );
    }

    public function testASingleElementRunShowsTheResourceEndpointTemplate(): void
    {
        $link = FakeLink::make([
            'endpoint'      => 'https://api.test/articles',
            'itemEndpoint'  => 'https://api.test/articles/{id}',
            'siteEndpoints' => [['site' => 'nl', 'endpoint' => 'https://api.test/nl/articles']],
        ]);

        $this->assertSame(
            ['endpointUrl' => 'https://api.test/articles/{id}', 'endpoints' => null],
            (new LinkPresenter())->endpointDisplay($link, 7, null),
        );
    }

    public function testASiteScopedRunShowsThatSitesEndpoint(): void
    {
        $link = FakeLink::make([
            'endpoint'      => 'https://api.test/articles',
            'siteEndpoints' => [['site' => 'nl', 'endpoint' => 'https://api.test/nl/articles']],
        ]);

        $presenter = new LinkPresenter();

        $this->assertSame(
            ['endpointUrl' => 'https://api.test/nl/articles', 'endpoints' => null],
            $presenter->endpointDisplay($link, null, 'nl'),
        );

        // A site with no dedicated endpoint falls back to the base.
        $this->assertSame(
            ['endpointUrl' => 'https://api.test/articles', 'endpoints' => null],
            $presenter->endpointDisplay($link, null, 'fr'),
        );
    }

    public function testAnAllSitesRunOnAPerSiteLinkListsEveryEndpoint(): void
    {
        $link = FakeLink::make([
            'endpoint'      => 'https://api.test/articles',
            'siteEndpoints' => [
                ['site' => 'nl', 'endpoint' => 'https://api.test/nl/articles'],
                ['site' => 'fr', 'endpoint' => 'https://api.test/fr/articles'],
            ],
        ]);

        $this->assertSame([
            'endpointUrl' => null,
            'endpoints'   => [
                ['site' => 'nl', 'url' => 'https://api.test/nl/articles'],
                ['site' => 'fr', 'url' => 'https://api.test/fr/articles'],
            ],
        ], (new LinkPresenter())->endpointDisplay($link, null, null));
    }

    public function testAnAllSitesRunOnASingleEndpointLinkShowsTheBase(): void
    {
        $link = FakeLink::make(['endpoint' => 'https://api.test/articles']);

        $this->assertSame(
            ['endpointUrl' => 'https://api.test/articles', 'endpoints' => null],
            (new LinkPresenter())->endpointDisplay($link, null, null),
        );
    }
}
