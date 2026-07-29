<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RunMemo;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * {@see RunMemo}: the run-scoped scratch memo the registry prototypes park their
 * per-run lookups in, instead of on themselves (a shared prototype's property
 * memo would outlive the run — the bug this replaced).
 */
class RunMemoTest extends Unit
{
    public function testResolvesOncePerKeyAndReturnsTheCachedValue(): void
    {
        $memo = new RunMemo();
        $calls = 0;
        $resolve = function() use (&$calls): array {
            $calls++;

            return ['editor' => 3];
        };

        $this->assertSame(['editor' => 3], $memo->remember('userTarget.groupIdMap', $resolve));
        $this->assertSame(['editor' => 3], $memo->remember('userTarget.groupIdMap', $resolve));
        $this->assertSame(1, $calls, 'The resolver runs once per run, however many items ask for it.');
    }

    public function testCachesANullResolution(): void
    {
        $memo = new RunMemo();
        $calls = 0;
        $resolve = function() use (&$calls) {
            $calls++;

            return null;
        };

        $this->assertNull($memo->remember('nothing', $resolve));
        $this->assertNull($memo->remember('nothing', $resolve));
        $this->assertSame(1, $calls, 'Null is a resolved answer, not a cold key.');
    }

    public function testKeysAreIndependent(): void
    {
        $memo = new RunMemo();

        $this->assertSame('a', $memo->remember('one', static fn(): string => 'a'));
        $this->assertSame('b', $memo->remember('two', static fn(): string => 'b'));
        $this->assertSame('a', $memo->remember('one', static fn(): string => 'never'));
    }

    public function testEveryRunGetsItsOwnMemo(): void
    {
        // The isolation that makes the memo safe: a fresh SyncContext per run
        // means a value cached in one run is never read by the next.
        $link = FakeLink::make();
        $target = new class() extends AbstractElementTarget {
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

        $first = new SyncContext(link: $link, target: $target);
        $second = new SyncContext(link: $link, target: $target);

        $this->assertSame('stale', $first->memo->remember('groups', static fn(): string => 'stale'));
        $this->assertSame('fresh', $second->memo->remember('groups', static fn(): string => 'fresh'));
    }
}
