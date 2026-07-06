<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Endpoint-shape migration for the missing-element policies
 * ({@see Link::migrateProcessingForEndpointShape()}): with site-specific
 * endpoints the global `disable`/`delete` flags swap to their `-for-site`
 * counterparts; without site endpoints the `-for-site` flags swap back. The
 * swap heals a mismatched config on save rather than rejecting it.
 */
class LinkProcessingMigrationTest extends Unit
{
    public function testGlobalDeleteBecomesForSiteWithSiteEndpoints(): void
    {
        $link = $this->link(true, [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE]);
        $migrations = $link->migrateProcessingForEndpointShape();

        $this->assertSame([Link::PROCESSING_CREATE, Link::PROCESSING_DELETE_FOR_SITE], $link->processing);
        $this->assertSame(
            [['from' => Link::PROCESSING_DELETE, 'to' => Link::PROCESSING_DELETE_FOR_SITE]],
            $migrations,
        );
    }

    public function testGlobalDisableBecomesForSiteWithSiteEndpoints(): void
    {
        $link = $this->link(true, [Link::PROCESSING_DISABLE]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([Link::PROCESSING_DISABLE_FOR_SITE], $link->processing);
    }

    public function testForSiteBecomesGlobalWithoutSiteEndpoints(): void
    {
        $link = $this->link(false, [Link::PROCESSING_DELETE_FOR_SITE, Link::PROCESSING_DISABLE_FOR_SITE]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([Link::PROCESSING_DELETE, Link::PROCESSING_DISABLE], $link->processing);
    }

    public function testMatchingShapeIsUnchangedAndReportsNoMigrations(): void
    {
        $link = $this->link(true, [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE_FOR_SITE]);
        $migrations = $link->migrateProcessingForEndpointShape();

        $this->assertSame([Link::PROCESSING_CREATE, Link::PROCESSING_DELETE_FOR_SITE], $link->processing);
        $this->assertSame([], $migrations);
    }

    public function testIsIdempotent(): void
    {
        $link = $this->link(true, [Link::PROCESSING_DELETE]);
        $link->migrateProcessingForEndpointShape();
        $second = $link->migrateProcessingForEndpointShape();

        $this->assertSame([], $second);
        $this->assertSame([Link::PROCESSING_DELETE_FOR_SITE], $link->processing);
    }

    public function testCollidingGlobalAndForSiteFormsDedupe(): void
    {
        // A config carrying both forms of the same policy collapses to one.
        $link = $this->link(true, [Link::PROCESSING_DELETE, Link::PROCESSING_DELETE_FOR_SITE]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([Link::PROCESSING_DELETE_FOR_SITE], $link->processing);
    }

    protected function link(bool $siteEndpoints, array $processing): Link
    {
        return FakeLink::make([
            'siteEndpoints' => $siteEndpoints
                ? [['site' => 'nl', 'endpoint' => 'https://example.test/nl']]
                : [],
            'processing' => $processing,
        ]);
    }
}
