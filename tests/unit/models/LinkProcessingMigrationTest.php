<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ProcessingAction;
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
        $link = $this->link(true, [ProcessingAction::CREATE->value, ProcessingAction::DELETE->value]);
        $migrations = $link->migrateProcessingForEndpointShape();

        $this->assertSame([ProcessingAction::CREATE->value, ProcessingAction::DELETE_FOR_SITE->value], $link->processing);
        $this->assertSame(
            [['from' => ProcessingAction::DELETE->value, 'to' => ProcessingAction::DELETE_FOR_SITE->value]],
            $migrations,
        );
    }

    public function testGlobalDisableBecomesForSiteWithSiteEndpoints(): void
    {
        $link = $this->link(true, [ProcessingAction::DISABLE->value]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([ProcessingAction::DISABLE_FOR_SITE->value], $link->processing);
    }

    public function testForSiteBecomesGlobalWithoutSiteEndpoints(): void
    {
        $link = $this->link(false, [ProcessingAction::DELETE_FOR_SITE->value, ProcessingAction::DISABLE_FOR_SITE->value]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([ProcessingAction::DELETE->value, ProcessingAction::DISABLE->value], $link->processing);
    }

    public function testMatchingShapeIsUnchangedAndReportsNoMigrations(): void
    {
        $link = $this->link(true, [ProcessingAction::CREATE->value, ProcessingAction::DELETE_FOR_SITE->value]);
        $migrations = $link->migrateProcessingForEndpointShape();

        $this->assertSame([ProcessingAction::CREATE->value, ProcessingAction::DELETE_FOR_SITE->value], $link->processing);
        $this->assertSame([], $migrations);
    }

    public function testIsIdempotent(): void
    {
        $link = $this->link(true, [ProcessingAction::DELETE->value]);
        $link->migrateProcessingForEndpointShape();
        $second = $link->migrateProcessingForEndpointShape();

        $this->assertSame([], $second);
        $this->assertSame([ProcessingAction::DELETE_FOR_SITE->value], $link->processing);
    }

    public function testCollidingGlobalAndForSiteFormsDedupe(): void
    {
        // A config carrying both forms of the same policy collapses to one.
        $link = $this->link(true, [ProcessingAction::DELETE->value, ProcessingAction::DELETE_FOR_SITE->value]);
        $link->migrateProcessingForEndpointShape();

        $this->assertSame([ProcessingAction::DELETE_FOR_SITE->value], $link->processing);
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
