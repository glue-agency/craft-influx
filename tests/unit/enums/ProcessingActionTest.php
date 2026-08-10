<?php

namespace GlueAgency\Influx\Tests\unit\enums;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * Behaviour spec for {@see ProcessingAction} — the processing vocabulary a link
 * stores in Project Config. The backed values and the default set are pinned
 * literally here (they're stored config, so they may not drift); the orders and
 * the global ⇄ per-site pairing are checked as derivations.
 */
class ProcessingActionTest extends Unit
{
    public function testStoredValuesArePinnedInCanonicalOrder(): void
    {
        $this->assertSame([
            'create',
            'update',
            'disable',
            'disable-for-site',
            'delete',
            'delete-for-site',
        ], ProcessingAction::values());
    }

    public function testDefaultsAreTheTwoNonDestructiveWrites(): void
    {
        $this->assertSame(['create', 'update'], ProcessingAction::defaults());
    }

    public function testOptionOrderGroupsGlobalPoliciesBeforePerSiteOnes(): void
    {
        // The builder's checkbox order: writes + global policies, then the
        // per-site counterparts, each group reading together.
        $this->assertSame([
            'create',
            'update',
            'disable',
            'delete',
            'disable-for-site',
            'delete-for-site',
        ], array_map(
            static fn(ProcessingAction $case): string => $case->value,
            ProcessingAction::optionOrder(),
        ));
    }

    public function testOptionOrderCoversEveryCaseExactlyOnce(): void
    {
        $this->assertEqualsCanonicalizing(ProcessingAction::values(), array_map(
            static fn(ProcessingAction $case): string => $case->value,
            ProcessingAction::optionOrder(),
        ));
        $this->assertCount(count(ProcessingAction::cases()), ProcessingAction::optionOrder());
    }

    public function testSiteCounterpartsPairTheMissingElementPolicies(): void
    {
        $this->assertSame(ProcessingAction::DISABLE_FOR_SITE, ProcessingAction::DISABLE->siteCounterpart());
        $this->assertSame(ProcessingAction::DELETE_FOR_SITE, ProcessingAction::DELETE->siteCounterpart());

        $this->assertSame(ProcessingAction::DISABLE, ProcessingAction::DISABLE_FOR_SITE->globalCounterpart());
        $this->assertSame(ProcessingAction::DELETE, ProcessingAction::DELETE_FOR_SITE->globalCounterpart());
    }

    public function testWritesHaveNoCounterpartInEitherDirection(): void
    {
        foreach ([ProcessingAction::CREATE, ProcessingAction::UPDATE] as $case) {
            $this->assertNull($case->siteCounterpart());
            $this->assertNull($case->globalCounterpart());
            $this->assertFalse($case->isForSite());
        }
    }

    public function testCounterpartsAreExactInverses(): void
    {
        foreach (ProcessingAction::cases() as $case) {
            $forSite = $case->siteCounterpart();

            if ($forSite !== null) {
                $this->assertTrue($forSite->isForSite());
                $this->assertSame($case, $forSite->globalCounterpart());
                $this->assertNull($forSite->siteCounterpart(), 'A per-site policy has nothing further to narrow to.');
            }
        }
    }

    public function testPillColorsFollowTheConfigurationPalette(): void
    {
        $this->assertSame('blue', ProcessingAction::CREATE->color());
        $this->assertSame('green', ProcessingAction::UPDATE->color());
        $this->assertSame('gray', ProcessingAction::DISABLE->color());
        $this->assertSame('gray', ProcessingAction::DISABLE_FOR_SITE->color());
        $this->assertSame('red', ProcessingAction::DELETE->color());
        $this->assertSame('red', ProcessingAction::DELETE_FOR_SITE->color());
    }

    public function testEveryCaseHasALabelAndANote(): void
    {
        foreach (ProcessingAction::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->note());
            $this->assertNotSame($case->label(), $case->note());
        }
    }

    public function testAFullyCapableTargetSupportsEveryPolicy(): void
    {
        $target = $this->target(creating: true, sweeping: true);

        foreach (ProcessingAction::cases() as $case) {
            $this->assertNull($case->unsupportedReason($target), "{$case->value} should be supported.");
        }
    }

    public function testOnlyCreateIsGatedOnTheCreatingCapability(): void
    {
        $target = $this->target(creating: false, sweeping: true);

        foreach (ProcessingAction::cases() as $case) {
            $expected = $case === ProcessingAction::CREATE;
            $this->assertSame($expected, $case->unsupportedReason($target) !== null, "{$case->value} gating.");
        }
    }

    public function testOnlyTheMissingPoliciesAreGatedOnTheSweepingCapability(): void
    {
        $target = $this->target(creating: true, sweeping: false);

        foreach (ProcessingAction::cases() as $case) {
            $this->assertSame(
                $case->isMissingPolicy(),
                $case->unsupportedReason($target) !== null,
                "{$case->value} gating.",
            );
        }
    }

    public function testTheTwoReasonsAreDistinctAndNameTheElementType(): void
    {
        $target = $this->target(creating: false, sweeping: false);

        $create = ProcessingAction::CREATE->unsupportedReason($target);
        $sweep = ProcessingAction::DELETE->unsupportedReason($target);

        $this->assertNotSame($create, $sweep);
        $this->assertStringContainsString('Widget', $create);
        $this->assertStringContainsString('Widget', $sweep);
    }

    /**
     * A target whose capabilities are whatever the test asks for. Not a real
     * element type — {@see AbstractElementTarget::friendlyName()} falls back to
     * the class basename, which keeps the reason assertable without a booted app.
     */
    protected function target(bool $creating, bool $sweeping): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static bool $creating = true;

            public static bool $sweeping = true;

            public static function elementType(): string
            {
                return 'vendor\elements\Widget';
            }

            public static function supportsCreating(): bool
            {
                return static::$creating;
            }

            public static function supportsSweeping(): bool
            {
                return static::$sweeping;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new LogicException('Not exercised here.');
            }
        };

        $target::$creating = $creating;
        $target::$sweeping = $sweeping;

        return $target;
    }
}
