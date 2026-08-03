<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\web\LinkBuilderOptionsPresenter;

/**
 * The two flags the builder's processing checkboxes are filtered by, as they go
 * over the wire. The General tab hides a policy on both counts — a sweep policy
 * for an element type that can't be swept, and a per-site one on a link without
 * site-specific endpoints — so a mislabelled option is a checkbox that shows
 * where it can do nothing, which is what GT-107 reported.
 *
 * Both are derived from the enum rather than matched on the `-for-site` suffix
 * in JS, so this is the contract that keeps the SPA off string sniffing.
 */
class ProcessingActionOptionsTest extends Unit
{
    public function testForSiteMarksExactlyThePerSitePolicies(): void
    {
        $forSite = [];

        foreach ($this->options() as $option) {
            if ($option['forSite']) {
                $forSite[] = $option['value'];
            }
        }

        $this->assertSame(
            [ProcessingAction::DISABLE_FOR_SITE->value, ProcessingAction::DELETE_FOR_SITE->value],
            $forSite,
        );
    }

    public function testEveryPerSitePolicyIsAlsoAMissingPolicy(): void
    {
        // The tab's filter reads `forSite` only for options it already knows are
        // sweep policies, so a per-site option that wasn't one would never show.
        foreach ($this->options() as $option) {
            if ($option['forSite']) {
                $this->assertTrue($option['missingPolicy'], "'{$option['value']}' is per-site but not a missing policy.");
            }
        }
    }

    public function testTheWritesAreNeitherSweepNorPerSite(): void
    {
        foreach ($this->options() as $option) {
            if (in_array($option['value'], [ProcessingAction::CREATE->value, ProcessingAction::UPDATE->value], true)) {
                $this->assertFalse($option['missingPolicy']);
                $this->assertFalse($option['forSite']);
            }
        }
    }

    public function testEveryCaseShipsWithBothFlags(): void
    {
        $options = $this->options();

        $this->assertCount(count(ProcessingAction::cases()), $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('missingPolicy', $option);
            $this->assertArrayHasKey('forSite', $option);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function options(): array
    {
        return (new LinkBuilderOptionsPresenter())->processingActionOptions();
    }
}
