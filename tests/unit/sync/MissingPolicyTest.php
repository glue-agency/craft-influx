<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;

/**
 * Spec for the single resolver that maps a link's `processing` flags onto the
 * one missing-elements sweep — {@see SynchronizationService::perSitePolicy()}.
 * Resolved once per pass and applied in that same pass; there is no run-end
 * second sweep, and the flags no longer compose.
 *
 * Precedence — the more destructive wins, and a global delete supersedes the
 * rest (no point disabling elements you're about to delete):
 *
 *   DELETE > DELETE_FOR_SITE > DISABLE > DISABLE_FOR_SITE
 *
 * null when no missing-elements flag is set.
 *
 * No Craft boot: the resolver reads only {@see Link::$processing}, and the
 * service's init() builds a plain {@see \GlueAgency\Influx\sync\ItemProcessor}
 * that touches no app services — so a bare service instance is enough. The
 * method is protected, so an anonymous subclass exposes it (same seam the rest
 * of the suite favours over reflection).
 */
class MissingPolicyTest extends Unit
{
    public function testNoneEnabledYieldsNull(): void
    {
        $this->assertResolves(['create', 'update'], null);
        $this->assertResolves([], null);
    }

    public function testDisableOnly(): void
    {
        $this->assertResolves(['create', 'update', 'disable'], ItemAction::DISABLED);
    }

    public function testDisableForSiteOnly(): void
    {
        $this->assertResolves(['update', 'disable-for-site'], ItemAction::DISABLED_FOR_SITE);
    }

    public function testDisableBeatsDisableForSite(): void
    {
        // They never coexist after save-time migration, but precedence is
        // deterministic: global disable is listed first.
        $this->assertResolves(['disable', 'disable-for-site'], ItemAction::DISABLED);
    }

    public function testDeleteForSiteOnly(): void
    {
        $this->assertResolves(['update', 'delete-for-site'], ItemAction::DELETED_FOR_SITE);
    }

    public function testDeleteOnly(): void
    {
        $this->assertResolves(['update', 'delete'], ItemAction::DELETED);
    }

    public function testDeleteForSiteBeatsDisable(): void
    {
        $this->assertResolves(['disable', 'delete-for-site'], ItemAction::DELETED_FOR_SITE);
    }

    public function testDeleteBeatsDisable(): void
    {
        // A global delete supersedes disable in the single pass — no reason to
        // also disable elements about to be deleted outright.
        $this->assertResolves(['disable', 'delete'], ItemAction::DELETED);
    }

    public function testDeleteBeatsDeleteForSite(): void
    {
        $this->assertResolves(['delete-for-site', 'delete'], ItemAction::DELETED);
    }

    public function testDeleteBeatsEverything(): void
    {
        $this->assertResolves(['disable', 'delete-for-site', 'delete'], ItemAction::DELETED);
    }

    /**
     * Assert the resolver for a link with the given processing flags.
     *
     * @param list<string> $processing
     */
    private function assertResolves(array $processing, ?ItemAction $expected): void
    {
        $link = new Link();
        $link->processing = $processing;

        $service = new class() extends SynchronizationService {
            public function publicPerSitePolicy(Link $link): ?ItemAction
            {
                return $this->perSitePolicy($link);
            }
        };

        $this->assertSame($expected, $service->publicPerSitePolicy($link));
    }
}
