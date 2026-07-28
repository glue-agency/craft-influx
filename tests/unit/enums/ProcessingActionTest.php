<?php

namespace GlueAgency\Influx\Tests\unit\enums;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ProcessingAction;

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
}
