<?php

namespace GlueAgency\Influx\Tests\unit\sync\run;

use Codeception\Test\Unit;
use GlueAgency\Influx\data\FeedPage;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\run\PageWalk;

/**
 * Spec for the progress denominator both page loops share —
 * {@see PageWalk::estimateTotal()}. It moved onto the walk accumulator when the
 * per-page loop was unified: the multiplier it needs (the first page's size) is
 * accumulated there, so the denominator belongs with it.
 *
 * The regression this pins: the queued loop used to multiply the page count by
 * the CURRENT page's size while the synchronous loop used the first page's, so
 * the two reported different totals once the final page came back short. The
 * multiplier is the FIRST page's size, always.
 *
 * No Craft boot: the helper reads only a {@see FeedPage}'s counts and the walk's
 * own first-page size.
 */
class ProgressTotalTest extends Unit
{
    public function testTheFeedsOwnTotalWins(): void
    {
        $this->assertSame(120, $this->estimate(new FeedPage(1, [], null, 120, 5), 25));
    }

    public function testPageCountIsMultipliedByTheFirstPageSize(): void
    {
        $this->assertSame(125, $this->estimate(new FeedPage(1, [], null, null, 5), 25));
    }

    public function testAShortFinalPageDoesNotShrinkTheEstimate(): void
    {
        $shortFinalPage = new FeedPage(5, [new RemoteItem(['id' => 1]), new RemoteItem(['id' => 2])], null, null, 5);

        $this->assertSame(125, $this->estimate($shortFinalPage, 25));
    }

    public function testNullWithoutATotalOrAPageCount(): void
    {
        $this->assertNull($this->estimate(new FeedPage(1, [], null), 25));
    }

    public function testNullWhenTheFirstPageWasEmpty(): void
    {
        $this->assertNull($this->estimate(new FeedPage(1, [], null, null, 5), 0));
    }

    public function testTheDenominatorIsMemoizedOnceItSettles(): void
    {
        // A synchronous run keeps ONE walk across every page, so a feed whose
        // reported counts drift mid-run can't move the denominator it settled on.
        $walk = new PageWalk(firstPageSize: 25);

        $this->assertSame(120, $walk->estimateTotal(new FeedPage(1, [], null, 120, 5)));
        $this->assertSame(120, $walk->estimateTotal(new FeedPage(2, [], null, 999, 5)));
    }

    private function estimate(FeedPage $page, int $firstPageSize): ?int
    {
        return (new PageWalk(firstPageSize: $firstPageSize))->estimateTotal($page);
    }
}
