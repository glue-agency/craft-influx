<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Spec for {@see SynchronizationService::applyMissingAction()}: it must report
 * the target save's boolean result so {@see SynchronizationService::sweepMissing()}
 * can record an ERROR row for a save that didn't persist — never a
 * false-positive success row (a real run once logged 603 'disabled' rows for
 * disables that never landed).
 *
 * No Craft boot: applyMissingAction() dispatches straight to the target's
 * disable()/delete() methods. A fake target (extending {@see AbstractElementTarget}
 * so only the action method needs overriding) returns false without touching
 * Craft's element service, and a {@see SyncContext} is built directly (its
 * constructor only assigns — {@see SyncContext::forSite()} is the Craft-touching
 * factory we avoid). The method is protected, so an anonymous subclass exposes it.
 */
class MissingActionResultTest extends Unit
{
    public function testGlobalDisableReturnsTheTargetResult(): void
    {
        $failing = $this->targetReturning(false);
        $this->assertFalse($this->apply(ItemAction::DISABLED, $failing, null));
        $this->assertSame(1, $failing->disableCalls);

        $ok = $this->targetReturning(true);
        $this->assertTrue($this->apply(ItemAction::DISABLED, $ok, null));
    }

    public function testUnknownPolicyReportsFalse(): void
    {
        // SKIPPED isn't a missing-action policy — nothing is applied, so it must
        // report false (never log it as a completed sweep action).
        $target = $this->targetReturning(true);

        $this->assertFalse($this->apply(ItemAction::SKIPPED, $target, null));
        $this->assertSame(0, $target->disableCalls);
    }

    /**
     * Invoke applyMissingAction() against a context wrapping the given target
     * and site scope, on a throwaway element.
     */
    protected function apply(ItemAction $policy, object $target, ?int $siteId): bool
    {
        $context = new SyncContext(
            link: FakeLink::make(),
            target: $target,
            siteId: $siteId,
        );

        $element = $this->makeElement();

        $service = new class() extends SynchronizationService {
            public function publicApply(SyncContext $context, ItemAction $policy, ElementInterface $element): bool
            {
                return $this->applyMissingAction($context, $policy, $element);
            }
        };

        return $service->publicApply($context, $policy, $element);
    }

    /**
     * A target whose disable() records the call and returns $result, leaving
     * every other AbstractElementTarget default untouched.
     */
    protected function targetReturning(bool $result): object
    {
        return new class($result) extends AbstractElementTarget {
            public bool $result = false;
            public int $disableCalls = 0;

            public function __construct(bool $result)
            {
                $this->result = $result;
            }

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

            public function disable(ElementInterface $element): bool
            {
                $this->disableCalls++;

                return $this->result;
            }
        };
    }

    /**
     * A bare element stand-in — applyMissingAction() only passes it through to
     * the target, which here ignores it.
     */
    protected function makeElement(): ElementInterface
    {
        return new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };
    }
}
