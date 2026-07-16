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
 * Routing spec for the single missing-elements sweep
 * ({@see SynchronizationService::sweepMissing()}). One pass resolves one policy
 * and applies it in that same pass — there is no run-end second sweep and the
 * flags no longer compose. The corrected semantics guarding the original
 * multi-site incident (a global delete run per site against DISJOINT feeds
 * deleting one site's elements as "missing" from another's feed):
 *
 *   - DISABLED / DELETED_FOR_SITE — sweep scoped to the just-finished site.
 *   - DELETED (global delete) — only ever resolves on a no-site-endpoints link
 *     (validation forbids DELETE + site endpoints), so its single pass is the
 *     unscoped `[null]` scope and the delete is cross-site (siteId null).
 *   - D2 guard: a link that somehow pairs DELETE with site endpoints (a
 *     hand-edited config) skips the delete and records a SKIPPED row rather
 *     than deleting cross-site off one site's feed.
 *   - Any unattributed errors block the sweep.
 *   - Offset (sliding-window) run — sweep skipped SILENTLY: a partial feed's
 *     complement isn't "missing", so only a full sync may delete/disable.
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
        $link = $this->link(['update', 'delete-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $service = $this->service();
        $context = $this->context($link, siteId: 7, siteHandle: 'fr');

        $service->publicSweepMissing($context, [9], 0, $this->log());

        $this->assertSame([[ItemAction::DELETED_FOR_SITE, [9], 7]], $service->sweeps);
    }

    public function testDisableForSiteSweepsPerSiteWhenScoped(): void
    {
        $link = $this->link(['update', 'disable-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $service = $this->service();
        $context = $this->context($link, siteId: 7, siteHandle: 'fr');

        $service->publicSweepMissing($context, [9], 0, $this->log());

        $this->assertSame([[ItemAction::DISABLED_FOR_SITE, [9], 7]], $service->sweeps);
    }

    public function testDisableForSiteSkipsWhenNotScopedToASite(): void
    {
        // Like delete-for-site, the per-site disable needs a site scope; the
        // [null] pass records a skip and sweeps nothing.
        $service = $this->service();
        $context = $this->context($this->link(['disable-for-site']), siteId: null, siteHandle: null);

        $service->publicSweepMissing($context, [1], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    public function testDeleteSweepsUnscopedOnNoSiteEndpointsLink(): void
    {
        // A no-site-endpoints link runs its single pass with siteId null; the
        // global delete sweeps cross-site (siteId null → target uses
        // siteId('*')->unique() and delete() removes the whole element).
        $service = $this->service();
        $context = $this->context($this->link(['update', 'delete']), siteId: null, siteHandle: null);

        $service->publicSweepMissing($context, [1, 2, 3], 0, $this->log());

        $this->assertSame([[ItemAction::DELETED, [1, 2, 3], null]], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testDeleteOnSiteEndpointsLinkSkipsViaD2Guard(): void
    {
        // A hand-edited config pairing DELETE with site endpoints must never
        // delete cross-site off one site's feed — the D2 guard skips it and
        // records a SKIPPED row.
        $link = $this->link(['update', 'delete']);
        $link->siteEndpoints = [
            ['site' => 'nl', 'endpoint' => 'https://example.test/nl'],
            ['site' => 'fr', 'endpoint' => 'https://example.test/fr'],
        ];
        $service = $this->service();
        $context = $this->context($link, siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
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
        // delete-for-site with no site scope (the [null] pass) can't scope the
        // deletion — records a skip and sweeps nothing.
        $service = $this->service();
        $context = $this->context($this->link(['delete-for-site']), siteId: null, siteHandle: null);

        $service->publicSweepMissing($context, [1], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertCount(1, $service->skips);
    }

    public function testNoMissingFlagSweepsNothing(): void
    {
        $service = $this->service();
        $context = $this->context($this->link(['create', 'update']), siteId: 5, siteHandle: 'nl');

        $service->publicSweepMissing($context, [1, 2], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testOffsetRunNeverDeletes(): void
    {
        // A sliding-window (offset) run fetches only a slice of the feed, so the
        // seen-set is partial — its complement isn't missing, just outside the
        // window. The sweep must NOT fire, and silently (expected behaviour):
        // no delete, no skip row.
        $service = $this->service();
        $context = $this->context($this->link(['update', 'delete']), siteId: null, siteHandle: null, offsetHandle: 'hour');

        $service->publicSweepMissing($context, [1, 2, 3], 0, $this->log());

        $this->assertSame([], $service->sweeps);
        $this->assertSame([], $service->skips);
    }

    public function testOffsetRunNeverDisablesEvenWhenScoped(): void
    {
        // disable-for-site scoped to a site would normally sweep; an offset run
        // must still block it — the guard is policy-agnostic.
        $link = $this->link(['update', 'disable-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $service = $this->service();
        $context = $this->context($link, siteId: 7, siteHandle: 'fr', offsetHandle: 'day');

        $service->publicSweepMissing($context, [9], 0, $this->log());

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
    protected function context(Link $link, ?int $siteId, ?string $siteHandle, ?string $offsetHandle = null): SyncContext
    {
        return new SyncContext(
            link: $link,
            target: $this->target(),
            siteId: $siteId,
            siteHandle: $siteHandle,
            offsetHandle: $offsetHandle,
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
