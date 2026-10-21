<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\item\RemoteItem;

/**
 * `has()` answers what `get()` can't: whether the item carries the key at all.
 * Both return null for `{"caption": null}` and for an item without `caption`,
 * and only the first is the feed saying "no value".
 */
class RemoteItemHasTest extends Unit
{
    public function testAKeyHoldingNullIsPresent(): void
    {
        $item = new RemoteItem(['caption' => null, 'image' => ['alt' => null]]);

        $this->assertTrue($item->has('caption'));
        $this->assertTrue($item->has('image.alt'));
    }

    public function testAMissingKeyIsNot(): void
    {
        $item = new RemoteItem(['image' => ['url' => 'a.jpg']]);

        $this->assertFalse($item->has('caption'));
        $this->assertFalse($item->has('image.alt'));
        $this->assertFalse($item->has(''));
    }

    public function testOnlyTheKeyTheNodeNamesCounts(): void
    {
        // `image: null` says there is no image; it says nothing about an alt
        // text the node reaches for inside one.
        $item = new RemoteItem(['image' => null, 'title' => 'x']);

        $this->assertFalse($item->has('image.alt'));
        $this->assertFalse($item->has('title.alt'));
    }

    public function testACollapsedListHopAnswersForAnyElement(): void
    {
        $item = new RemoteItem(['blocks' => [['text' => 'a'], ['caption' => null]]]);

        $this->assertTrue($item->has('blocks.caption'));
        $this->assertFalse($item->has('blocks.credit'));
    }

    public function testAnIndexedHopAnswersForThatElementOnly(): void
    {
        $item = new RemoteItem(['blocks' => [['text' => 'a'], ['caption' => null]]]);

        $this->assertTrue($item->has('blocks.1.caption'));
        $this->assertFalse($item->has('blocks.0.caption'));
        $this->assertFalse($item->has('blocks.2.caption'));
    }
}
