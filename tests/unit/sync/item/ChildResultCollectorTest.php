<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\ChildResultCollector;

/**
 * Behaviour spec for {@see ChildResultCollector}: a frame hands back what was
 * added to it, an empty frame closes to null (a row nesting nothing keeps null
 * children), nested frames stay separate, and both halves stay no-ops with no
 * frame open — a strategy exercised outside a collecting walk must still run.
 */
class ChildResultCollectorTest extends Unit
{
    public function testAFrameHandsBackWhatWasAddedToIt(): void
    {
        $collector = new ChildResultCollector();
        $first = new ChildResult(title: 'First');
        $second = new ChildResult(title: 'Second');

        $collector->open();
        $collector->add($first);
        $collector->add($second);

        $this->assertSame([$first, $second], $collector->close());
    }

    public function testAnEmptyFrameClosesToNull(): void
    {
        $collector = new ChildResultCollector();

        $collector->open();

        $this->assertNull($collector->close());
    }

    public function testAddWithoutAnOpenFrameIsANoOp(): void
    {
        $collector = new ChildResultCollector();
        $collector->add(new ChildResult(title: 'Orphan'));

        // The dropped child must not leak into the next frame either.
        $collector->open();

        $this->assertNull($collector->close());
    }

    public function testCloseWithoutAFrameReturnsNull(): void
    {
        $this->assertNull((new ChildResultCollector())->close());
    }

    public function testNestedFramesKeepTheirOwnChildren(): void
    {
        $collector = new ChildResultCollector();
        $outer = new ChildResult(title: 'Outer');
        $inner = new ChildResult(title: 'Inner');

        $collector->open();
        $collector->add($outer);

        $collector->open();
        $collector->add($inner);

        $this->assertSame([$inner], $collector->close());
        $this->assertSame([$outer], $collector->close());
    }
}
