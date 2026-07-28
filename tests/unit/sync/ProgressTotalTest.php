<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\data\FeedPage;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * Spec for the progress denominator both page loops share —
 * {@see SynchronizationService::estimatedTotal()}.
 *
 * The regression this pins: the queued loop used to multiply the page count by
 * the CURRENT page's size while the synchronous loop used the first page's, so
 * the two reported different totals once the final page came back short. The
 * multiplier is the FIRST page's size, always.
 *
 * No Craft boot: the helper reads only a {@see FeedPage}'s counts.
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

    private function estimate(FeedPage $page, int $firstPageSize): ?int
    {
        $service = new class() extends SynchronizationService {
            public function publicEstimatedTotal(FeedPage $page, int $firstPageSize): ?int
            {
                return $this->estimatedTotal($page, $firstPageSize);
            }
        };

        return $service->publicEstimatedTotal($page, $firstPageSize);
    }
}
