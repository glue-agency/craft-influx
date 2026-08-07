<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\item\RemoteItem;

/**
 * Behaviour spec for {@see RemoteItem::each()} — the positional read that
 * {@see RemoteItem::get()} deliberately isn't. `get()` collapses a list hop and
 * drops nulls, which is right for "every director's name" and wrong for
 * anything whose position carries meaning; `each()` hands back one item per
 * element instead, so child paths resolve per element and stay aligned.
 */
class RemoteItemEachTest extends Unit
{
    public function testEnumeratesEachElementAsItsOwnItem(): void
    {
        $item = new RemoteItem([
            'blocks' => [
                ['text' => 'first'],
                ['text' => 'second'],
                ['text' => 'third'],
            ],
        ]);

        $elements = $item->each('blocks');

        $this->assertCount(3, $elements);
        $this->assertSame(
            ['first', 'second', 'third'],
            array_map(static fn(RemoteItem $element): mixed => $element->get('text'), $elements),
        );
    }

    /**
     * The misalignment this method exists to fix: `get()` fans `blocks.image`
     * over the list and drops the null the middle element yields, so the third
     * element's image arrives at index 1 — on the SECOND block. Read per
     * element, every image stays on the element that carried it.
     */
    public function testPositionSurvivesAnElementMissingTheKey(): void
    {
        $item = new RemoteItem([
            'blocks' => [
                ['text' => 'a', 'image' => 'a.jpg'],
                ['text' => 'b'],
                ['text' => 'c', 'image' => 'c.jpg'],
            ],
        ]);

        $this->assertSame(['a.jpg', 'c.jpg'], $item->get('blocks.image'));

        $images = array_map(
            static fn(RemoteItem $element): mixed => $element->get('image'),
            $item->each('blocks'),
        );

        $this->assertSame(['a.jpg', null, 'c.jpg'], $images);
    }

    public function testChildPathsAreRelativeToTheElement(): void
    {
        $item = new RemoteItem([
            'blocks' => [
                ['meta' => ['position' => 'left']],
                ['meta' => ['position' => 'right']],
            ],
        ]);

        $elements = $item->each('blocks');

        $this->assertSame('left', $elements[0]->get('meta.position'));
        $this->assertSame('right', $elements[1]->get('meta.position'));
        // The element is the whole payload, so an absolute path finds nothing.
        $this->assertNull($elements[0]->get('blocks.meta.position'));
    }

    public function testASingleElementListStaysAList(): void
    {
        $item = new RemoteItem(['blocks' => [['text' => 'only']]]);

        $elements = $item->each('blocks');

        $this->assertCount(1, $elements);
        $this->assertSame('only', $elements[0]->get('text'));
    }

    /**
     * An empty list is a list — the feed is explicitly carrying no blocks,
     * which callers write through as a clear. Distinct from the null a node
     * holding no list at all yields.
     */
    public function testAnEmptyListYieldsNoElementsRatherThanNull(): void
    {
        $this->assertSame([], (new RemoteItem(['blocks' => []]))->each('blocks'));
    }

    public function testANodeHoldingNoListYieldsNull(): void
    {
        $this->assertNull((new RemoteItem(['blocks' => ['text' => 'a']]))->each('blocks'));
        $this->assertNull((new RemoteItem(['blocks' => 'a string']))->each('blocks'));
        $this->assertNull((new RemoteItem(['blocks' => 7]))->each('blocks'));
        $this->assertNull((new RemoteItem([]))->each('blocks'));
        $this->assertNull((new RemoteItem(['blocks' => []]))->each(''));
    }

    /**
     * Nothing can resolve a path against a scalar, so a list mixing objects
     * with bare values contributes only the objects.
     */
    public function testNonArrayElementsAreSkipped(): void
    {
        $item = new RemoteItem(['blocks' => [['text' => 'a'], 'noise', null, ['text' => 'b']]]);

        $elements = $item->each('blocks');

        $this->assertCount(2, $elements);
        $this->assertSame(['a', 'b'], array_map(
            static fn(RemoteItem $element): mixed => $element->get('text'),
            $elements,
        ));
    }

    public function testReadsAListNestedUnderAPath(): void
    {
        $item = new RemoteItem(['content' => ['blocks' => [['text' => 'a'], ['text' => 'b']]]]);

        $elements = $item->each('content.blocks');

        $this->assertCount(2, $elements);
        $this->assertSame('b', $elements[1]->get('text'));
    }
}
