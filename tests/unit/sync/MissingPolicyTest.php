<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;

/**
 * Spec for the two resolvers that map a link's `processing` flags onto the
 * missing-elements sweeps — {@see SynchronizationService::perSitePolicy()} and
 * {@see SynchronizationService::runPolicy()}. They are INDEPENDENT: per-site
 * and run-end sweeps COMPOSE, so a link flagged for both disable and delete
 * resolves a per-site DISABLED *and* a run-end DELETED (rather than delete
 * suppressing disable via a single precedence chain, as it once did).
 *
 *   - perSitePolicy — among the per-site flags, DELETE_FOR_SITE beats DISABLE;
 *     null when neither is set. The global DELETE flag does NOT participate.
 *   - runPolicy — DELETED when the global DELETE flag is set, else null.
 *
 * No Craft boot: both resolvers read only {@see Link::$processing}, and the
 * service's init() builds a plain {@see \GlueAgency\Influx\sync\ItemProcessor}
 * that touches no app services — so a bare service instance is enough. The
 * methods are protected, so an anonymous subclass exposes them (same seam the
 * rest of the suite favours over reflection).
 */
class MissingPolicyTest extends Unit
{
    public function testNoneEnabledYieldsNullForBoth(): void
    {
        $this->assertResolves(['create', 'update'], null, null);
        $this->assertResolves([], null, null);
    }

    public function testDisableOnlyIsPerSiteOnly(): void
    {
        $this->assertResolves(['create', 'update', 'disable'], ItemAction::DISABLED, null);
    }

    public function testDeleteForSiteOnlyIsPerSiteOnly(): void
    {
        $this->assertResolves(['update', 'delete-for-site'], ItemAction::DELETED_FOR_SITE, null);
    }

    public function testDeleteOnlyIsRunOnly(): void
    {
        $this->assertResolves(['update', 'delete'], null, ItemAction::DELETED);
    }

    public function testDisableAndDeleteComposePerSiteDisableAndRunDelete(): void
    {
        // The real multi-site use case: per-site disable corrects cross-site
        // visibility leaks each run, the run-end delete removes elements missing
        // from every feed. Both fire — delete no longer suppresses disable.
        $this->assertResolves(['create', 'update', 'disable', 'delete'], ItemAction::DISABLED, ItemAction::DELETED);
    }

    public function testDeleteForSiteAndDeleteComposeDfsPerSiteAndRunDelete(): void
    {
        $this->assertResolves(['update', 'delete-for-site', 'delete'], ItemAction::DELETED_FOR_SITE, ItemAction::DELETED);
    }

    public function testDeleteForSiteBeatsDisablePerSite(): void
    {
        $this->assertResolves(['disable', 'delete-for-site'], ItemAction::DELETED_FOR_SITE, null);
    }

    public function testAllThreeComposeDfsPerSiteAndRunDelete(): void
    {
        // DELETE_FOR_SITE wins the per-site slot over DISABLE; DELETE owns the
        // run slot independently.
        $this->assertResolves(['disable', 'delete-for-site', 'delete'], ItemAction::DELETED_FOR_SITE, ItemAction::DELETED);
    }

    /**
     * Assert both resolvers for a link with the given processing flags.
     *
     * @param list<string> $processing
     */
    private function assertResolves(array $processing, ?ItemAction $expectedPerSite, ?ItemAction $expectedRun): void
    {
        $link = new Link();
        $link->processing = $processing;

        $service = new class() extends SynchronizationService {
            public function publicPerSitePolicy(Link $link): ?ItemAction
            {
                return $this->perSitePolicy($link);
            }

            public function publicRunPolicy(Link $link): ?ItemAction
            {
                return $this->runPolicy($link);
            }
        };

        $this->assertSame($expectedPerSite, $service->publicPerSitePolicy($link), 'per-site policy');
        $this->assertSame($expectedRun, $service->publicRunPolicy($link), 'run policy');
    }
}
