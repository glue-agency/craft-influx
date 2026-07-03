<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Routing spec for the two missing-elements sweep entry points — the fix for a
 * real multi-site incident where a per-site global-delete sweep, run once per
 * site against DISJOINT per-site feeds, deleted 605 of 639 entries (site A's
 * sweep deleted site B's feed elements as "missing", site B's re-created them,
 * then site B's sweep deleted site A's). The corrected semantics:
 *
 *   - DISABLED / DELETED_FOR_SITE — sweep PER SITE (in {@see SynchronizationService::sweepMissing()}),
 *     scoped to the just-finished site.
 *   - DELETED (global delete) — sweep ONCE PER RUN (in
 *     {@see SynchronizationService::sweepMissingForRun()}), after the last
 *     site, over the union of every site's seen-set — never per site.
 *   - The two sweeps COMPOSE: a link flagged for both disable and delete fires
 *     the per-site disable AND the run-end delete — the real multi-site case
 *     where per-site disable corrects cross-site leaks and run-end delete
 *     removes elements missing from every feed.
 *   - A site-scoped run on a multi-endpoint link refuses the global-delete
 *     sweep (it can't see the other sites' feeds) and records a SKIPPED row.
 *   - Any site's unattributed errors block the run-end global-delete sweep.
 *
 * No Craft boot: {@see SynchronizationService::applySweep()} (the code that
 * actually queries + deletes) and {@see SynchronizationService::logSweepSkip()}
 * (the code that writes the SKIPPED row) are the two seams these methods route
 * through, so an anonymous subclass overrides both to RECORD invocations
 * instead of touching Craft. What we assert is which seam fired, with which
 * scope — i.e. the routing, which is the whole of the fix.
 */
class MissingSweepRoutingTest extends Unit
{
    // -- per-site sweep (sweepMissing) ----------------------------------------

    public function testDisabledSweepsPerSite(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['update', 'disable']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([[ItemAction::DISABLED, [1, 2], 5]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testDeleteForSiteSweepsPerSiteWhenScoped(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['update', 'delete-for-site']), siteId: 7, siteHandle: 'fr');

        $service->publicSweepMissing($context, [9], 0, $this->log());

        $this->assertSame([[ItemAction::DELETED_FOR_SITE, [9], 7]], $service->sweeps);
    }

    public function testDeleteDoesNotSweepPerSite(): void
    {
        // The incident's root cause: global delete must NEVER sweep in the
        // per-site path — sweepMissing() is a no-op for it (the run-end sweep
        // owns it), so a per-site pass can't delete another site's feed.
        $service = $this->service();
        $context = $this->context($this->link(['update', 'delete']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testPerSiteSweepBailsOnUnattributedErrors(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['disable']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1], 3, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    public function testDeleteForSiteSkipsWhenNotScopedToASite(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['delete-for-site']), siteId: null, siteHandle: null);

        $service->publicSweepMissing($context, [1], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    // -- run-end sweep (sweepMissingForRun) -----------------------------------

    public function testDeleteSweepsOnceAtRunEndWithTheUnionUnscoped(): void
    {
        // The union of every site's seen ids, swept once, siteId null (unscoped
        // → the target uses siteId('*')->unique()). An element is deleted only
        // when NO site mentioned it.
        $service = $this->service();
        $context = $this->context($this->link(['update', 'delete']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2, 3], 0, $this->log(), null);

        $this->assertSame([[ItemAction::DELETED, [1, 2, 3], null]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testRunEndSweepIsNoopForPerSitePolicies(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['disable']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2], 0, $this->log(), null);

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testSiteScopedDeleteOnMultiEndpointLinkSkipsAndSweepsNothing(): void
    {
        // A run restricted to one site of a link with per-site endpoints can't
        // read the other feeds that co-own the delete decision — it must record
        // a skip and sweep nothing (the incident's disjoint-feed shape).
        $link = $this->link(['delete']);
        $link->siteEndpoints = [
            ['site' => 'nl', 'endpoint' => 'https://example.test/nl'],
            ['site' => 'fr', 'endpoint' => 'https://example.test/fr'],
        ];
        $service = $this->service();
        $context = $this->context($link, siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2], 0, $this->log(), 'nl');

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    public function testSiteScopedDeleteOnSingleEndpointLinkStillSweeps(): void
    {
        // A single-endpoint (or single-site) link covers "every site" trivially,
        // so a site-scoped run may still sweep.
        $link = $this->link(['delete']);
        $link->siteEndpoints = [
            ['site' => 'nl', 'endpoint' => 'https://example.test/nl'],
        ];
        $service = $this->service();
        $context = $this->context($link, siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2], 0, $this->log(), 'nl');

        $this->assertSame([[ItemAction::DELETED, [1, 2], null]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testRunEndDeleteBailsWhenAnySiteHadUnattributedErrors(): void
    {
        // Site 1 errored, site 2 was clean — the run-wide error count is what
        // reaches the run-end sweep, and any at all blocks the whole delete.
        $service = $this->service();
        $context = $this->context($this->link(['delete']), siteId: 5, siteHandle: 'fr');

        $service->publicSweepMissingForRun($context, [1, 2, 3], 2, $this->log(), null);

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    // -- composition (both sweeps fire in one run) ----------------------------

    public function testDisableAndDeleteLinkFiresPerSiteDisable(): void
    {
        // Composed link: the per-site path still fires the DISABLE (delete no
        // longer suppresses it), scoped to the just-finished site.
        $service = $this->service();
        $context = $this->context($this->link(['disable', 'delete']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([[ItemAction::DISABLED, [1, 2], 5]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testDisableAndDeleteLinkFiresRunEndDelete(): void
    {
        // Same composed link: the run-end path fires the DELETE over the union,
        // unscoped. Both sweeps run for one link in one run.
        $service = $this->service();
        $context = $this->context($this->link(['disable', 'delete']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2, 3], 0, $this->log(), null);

        $this->assertSame([[ItemAction::DELETED, [1, 2, 3], null]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testDeleteForSiteAndDeleteLinkFiresBothSweeps(): void
    {
        // delete-for-site per site + global delete at run end.
        $link = $this->link(['delete-for-site', 'delete']);

        $perSite = $this->service();
        $perSite->publicSweepMissing($this->context($link, siteId: 7, siteHandle: 'fr'), [9], 0, $this->log());
        $this->assertSame([[ItemAction::DELETED_FOR_SITE, [9], 7]], $perSite->sweeps);

        $runEnd = $this->service();
        $runEnd->publicSweepMissingForRun($this->context($link, siteId: 7, siteHandle: 'fr'), [9, 10], 0, $this->log(), null);
        $this->assertSame([[ItemAction::DELETED, [9, 10], null]], $runEnd->sweeps);
    }

    public function testDisableOnlyFiresNoRunEndSweep(): void
    {
        // Disable-only link: per-site disable only, the run-end sweep is a
        // no-op (no DELETE flag) — nothing swept, no skip row.
        $service = $this->service();
        $context = $this->context($this->link(['disable']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissingForRun($context, [1, 2], 0, $this->log(), null);

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testDeleteOnlyFiresNoPerSiteSweep(): void
    {
        // Delete-only link: run-end delete only, the per-site sweep is a no-op
        // (no per-site flag) — nothing swept, no skip row.
        $service = $this->service();
        $context = $this->context($this->link(['delete']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * A service whose two sweep seams record instead of act:
     *   - $sweeps: [policy, seenIds, siteId] per applySweep() call.
     *   - $skips:  the message per logSweepSkip() call.
     */
    protected function service(): object
    {
        return new class() extends SynchronizationService {
            /** @var list<array{0: ItemAction, 1: list<int>, 2: ?int}> */
            public array $sweeps = [];

            /** @var list<string> */
            public array $skips = [];

            public function init(): void
            {
                // Skip parent init() — it builds an ItemProcessor we don't need,
                // and we never run the per-item pipeline here.
            }

            public function publicSweepMissing(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log): void
            {
                $this->sweepMissing($context, $seenIds, $unattributedErrors, $log);
            }

            public function publicSweepMissingForRun(SyncContext $context, array $seenIds, int $unattributedErrors, LogRecord $log, ?string $requestedSite): void
            {
                $this->sweepMissingForRun($context, $seenIds, $unattributedErrors, $log, $requestedSite);
            }

            protected function applySweep(SyncContext $context, ItemAction $policy, array $seenIds, ?int $siteId, LogRecord $log): void
            {
                $this->sweeps[] = [$policy, $seenIds, $siteId];
            }

            protected function logSweepSkip(LogRecord $log, string $message): void
            {
                $this->skips[] = $message;
            }

            protected function warnSweepSkipped(string $message): void
            {
                // No Craft log in a pure-logic test — the skip row (recorded via
                // logSweepSkip) is what the routing assertions check.
            }
        };
    }

    /**
     * A link with the given processing flags, match attribute set so the
     * resolvers/sweeps treat it as sync-capable.
     *
     * @param list<string> $processing
     */
    protected function link(array $processing): Link
    {
        return FakeLink::make(['processing' => $processing]);
    }

    /**
     * A context wrapping a throwaway target (never touched — applySweep is
     * stubbed) at the given site scope.
     */
    protected function context(Link $link, ?int $siteId, ?string $siteHandle): SyncContext
    {
        return new SyncContext(
            link: $link,
            target: $this->target(),
            siteId: $siteId,
            siteHandle: $siteHandle,
        );
    }

    /**
     * A bare target — the sweep routing never calls into it (applySweep, the
     * only method that would, is stubbed), so only the abstract contract needs
     * satisfying.
     */
    protected function target(): object
    {
        return new class() extends AbstractElementTarget {
            public static function elementType(): string
            {
                return ElementInterface::class;
            }

            public function claimsElement(Link $link, ElementInterface $element): bool
            {
                return false;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new RuntimeException('not needed');
            }
        };
    }

    /**
     * A LogRecord stand-in — the sweep routing only passes it to the stubbed
     * seams, so its constructor's Craft dependencies are skipped.
     */
    protected function log(): LogRecord
    {
        return new class() extends LogRecord {
            public function __construct()
            {
                // Skip ActiveRecord::init()'s schema lookup — never persisted here.
            }
        };
    }
}
