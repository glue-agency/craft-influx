<?php

namespace GlueAgency\Influx\Tests\unit\sync\run;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\run\MissingElementsSweeper;
use GlueAgency\Influx\sync\run\MissingSweepPlan;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Routing spec for the single missing-elements sweep
 * ({@see MissingElementsSweeper::plan()}). One pass resolves one policy and
 * applies it in that same pass — there is no run-end second sweep and the flags
 * no longer compose. The corrected semantics guarding the original multi-site
 * incident (a global delete run per site against DISJOINT feeds deleting one
 * site's elements as "missing" from another's feed):
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
 *   - Capability guard: an element type whose target can't sweep
 *     ({@see ElementTargetInterface::supportsSweeping()} = false, i.e. User) skips
 *     and REPORTS, so a stored policy the builder no longer offers can't fail
 *     silently.
 *
 * No Craft boot, and nothing stubbed: plan() is the pure decision half of the
 * sweeper — it resolves the policy, runs every guard, and hands back a
 * {@see MissingSweepPlan} without touching the database or the log. The
 * database-touching half ({@see MissingElementsSweeper::apply()}) and the
 * SKIPPED-row write both consume that plan, so asserting the plan asserts the
 * routing, which is the whole of the fix: `$plan->policy` + `$plan->siteId` is
 * the sweep that would fire, and `$plan->skipRow` is the row a bail leaves
 * behind.
 */
class MissingSweepRoutingTest extends Unit
{
    public function testDisabledSweepsPerSite(): void
    {
        $context = $this->context($this->link(['update', 'disable']), siteId: 5, siteHandle: 'nl');

        $plan = $this->plan($context, [1, 2], 0);

        $this->assertSame(ItemAction::DISABLED, $plan->policy);
        $this->assertSame([1, 2], $plan->seenIds);
        $this->assertSame(5, $plan->siteId);
        $this->assertNull($plan->skipRow);
    }

    public function testDeleteForSiteSweepsPerSiteWhenScoped(): void
    {
        $link = $this->link(['update', 'delete-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $context = $this->context($link, siteId: 7, siteHandle: 'fr');

        $plan = $this->plan($context, [9], 0);

        $this->assertSame(ItemAction::DELETED_FOR_SITE, $plan->policy);
        $this->assertSame([9], $plan->seenIds);
        $this->assertSame(7, $plan->siteId);
    }

    public function testDisableForSiteSweepsPerSiteWhenScoped(): void
    {
        $link = $this->link(['update', 'disable-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $context = $this->context($link, siteId: 7, siteHandle: 'fr');

        $plan = $this->plan($context, [9], 0);

        $this->assertSame(ItemAction::DISABLED_FOR_SITE, $plan->policy);
        $this->assertSame([9], $plan->seenIds);
        $this->assertSame(7, $plan->siteId);
    }

    public function testDisableForSiteSkipsWhenNotScopedToASite(): void
    {
        // Like delete-for-site, the per-site disable needs a site scope; the
        // [null] pass records a skip and sweeps nothing.
        $context = $this->context($this->link(['disable-for-site']), siteId: null, siteHandle: null);

        $plan = $this->plan($context, [1], 0);

        $this->assertNull($plan->policy);
        $this->assertNotNull($plan->skipRow);
    }

    public function testDeleteSweepsUnscopedOnNoSiteEndpointsLink(): void
    {
        // A no-site-endpoints link runs its single pass with siteId null; the
        // global delete sweeps cross-site (siteId null → target uses
        // siteId('*')->unique() and delete() removes the whole element).
        $context = $this->context($this->link(['update', 'delete']), siteId: null, siteHandle: null);

        $plan = $this->plan($context, [1, 2, 3], 0);

        $this->assertSame(ItemAction::DELETED, $plan->policy);
        $this->assertSame([1, 2, 3], $plan->seenIds);
        $this->assertNull($plan->siteId);
        $this->assertNull($plan->skipRow);
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
        $context = $this->context($link, siteId: 5, siteHandle: 'nl');

        $plan = $this->plan($context, [1, 2], 0);

        $this->assertNull($plan->policy);
        $this->assertNotNull($plan->skipRow);
    }

    public function testPerSiteSweepBailsOnUnattributedErrors(): void
    {
        $context = $this->context($this->link(['disable']), siteId: 5, siteHandle: 'nl');

        $plan = $this->plan($context, [1], 3);

        $this->assertNull($plan->policy);
        $this->assertNotNull($plan->skipRow);
        $this->assertNotNull($plan->warning);
    }

    public function testDeleteForSiteSkipsWhenNotScopedToASite(): void
    {
        // delete-for-site with no site scope (the [null] pass) can't scope the
        // deletion — records a skip and sweeps nothing.
        $context = $this->context($this->link(['delete-for-site']), siteId: null, siteHandle: null);

        $plan = $this->plan($context, [1], 0);

        $this->assertNull($plan->policy);
        $this->assertNotNull($plan->skipRow);
    }

    public function testANonSweepingElementTypeSkipsAndReports(): void
    {
        // The builder no longer offers the missing-element policies for a target
        // that reports supportsSweeping() = false, but config predating the flag
        // still carries them — the pass must say so rather than silently doing
        // nothing (which is what apply()'s null-query backstop would do).
        $context = $this->context($this->link(['update', 'delete']), siteId: null, siteHandle: null, target: $this->nonSweepingTarget());

        $plan = $this->plan($context, [1, 2], 0);

        $this->assertNull($plan->policy);
        $this->assertNotNull($plan->skipRow);
        $this->assertNotNull($plan->warning);
    }

    public function testANonSweepingElementTypeStaysSilentWithoutAPolicy(): void
    {
        // No missing-element flag: nothing to warn about, so the capability guard
        // must not fire either.
        $context = $this->context($this->link(['create', 'update']), siteId: null, siteHandle: null, target: $this->nonSweepingTarget());

        $plan = $this->plan($context, [1, 2], 0);

        $this->assertNull($plan->policy);
        $this->assertNull($plan->skipRow);
        $this->assertNull($plan->warning);
    }

    public function testNoMissingFlagSweepsNothing(): void
    {
        $context = $this->context($this->link(['create', 'update']), siteId: 5, siteHandle: 'nl');

        $plan = $this->plan($context, [1, 2], 0);

        $this->assertNull($plan->policy);
        $this->assertNull($plan->skipRow);
    }

    public function testOffsetRunNeverDeletes(): void
    {
        // A sliding-window (offset) run fetches only a slice of the feed, so the
        // seen-set is partial — its complement isn't missing, just outside the
        // window. The sweep must NOT fire, and silently (expected behaviour):
        // no delete, no skip row, no warning.
        $context = $this->context($this->link(['update', 'delete']), siteId: null, siteHandle: null, offsetHandle: 'hour');

        $plan = $this->plan($context, [1, 2, 3], 0);

        $this->assertNull($plan->policy);
        $this->assertNull($plan->skipRow);
        $this->assertNull($plan->warning);
    }

    public function testOffsetRunNeverDisablesEvenWhenScoped(): void
    {
        // disable-for-site scoped to a site would normally sweep; an offset run
        // must still block it — the guard is policy-agnostic.
        $link = $this->link(['update', 'disable-for-site']);
        $link->siteEndpoints = [['site' => 'fr', 'endpoint' => 'https://example.test/fr']];
        $context = $this->context($link, siteId: 7, siteHandle: 'fr', offsetHandle: 'day');

        $plan = $this->plan($context, [9], 0);

        $this->assertNull($plan->policy);
        $this->assertNull($plan->skipRow);
        $this->assertNull($plan->warning);
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * @param list<int> $seenIds
     */
    protected function plan(SyncContext $context, array $seenIds, int $unattributedErrors): MissingSweepPlan
    {
        return (new MissingElementsSweeper())->plan($context, $seenIds, $unattributedErrors);
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
     * A context wrapping a throwaway target (queried only for its sweeping
     * capability — plan() decides without touching the database) at the given
     * site scope.
     */
    protected function context(
        Link $link,
        ?int $siteId,
        ?string $siteHandle,
        ?string $offsetHandle = null,
        ?ElementTargetInterface $target = null,
    ): SyncContext {
        return new SyncContext(
            link: $link,
            target: $target ?? $this->target(),
            siteId: $siteId,
            siteHandle: $siteHandle,
            offsetHandle: $offsetHandle,
        );
    }

    /**
     * A bare target — the sweep routing calls nothing on it but the static
     * capability flags, so only the abstract contract needs satisfying. Sweepable,
     * per the base default.
     */
    protected function target(): ElementTargetInterface
    {
        return new class() extends AbstractElementTarget {
            public static function elementType(): string
            {
                return ElementInterface::class;
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
     * A target for an element type that can't be swept — what UserTarget reports.
     */
    protected function nonSweepingTarget(): ElementTargetInterface
    {
        return new class() extends AbstractElementTarget {
            public static function elementType(): string
            {
                return ElementInterface::class;
            }

            public static function supportsSweeping(): bool
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
}
