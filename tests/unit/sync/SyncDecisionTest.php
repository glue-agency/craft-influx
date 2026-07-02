<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\models\Link;

/**
 * Decision-matrix spec for {@see SyncDecision::decide()} — the rule that maps
 * (match value, found element, link processing flags) onto what a sync run
 * does with one remote item. Moved here from Link::decideAction(); both the
 * real run and the debug dry-run hang on it, so the matrix is locked in.
 *
 * No Craft boot: decide() only reads the link's `processing` array and tests
 * the element for null (never calling a method on it), so a plain Link and a
 * bare ElementInterface mock are enough.
 */
class SyncDecisionTest extends Unit
{
    public function testNoMatchValueSkips(): void
    {
        $link = $this->link();

        $this->assertSame(SyncDecision::SKIP_NO_MATCH, SyncDecision::decide($link, null, null));
        $this->assertSame(SyncDecision::SKIP_NO_MATCH, SyncDecision::decide($link, '', null));
    }

    public function testMissingElementCreatesWhenCreateEnabled(): void
    {
        $link = $this->link(['create', 'update']);

        $this->assertSame(SyncDecision::CREATE, SyncDecision::decide($link, 42, null));
    }

    public function testMissingElementSkipsWhenCreateDisabled(): void
    {
        $link = $this->link(['update']);

        $this->assertSame(SyncDecision::SKIP_NO_CREATE, SyncDecision::decide($link, 42, null));
    }

    public function testExistingElementUpdatesWhenUpdateEnabled(): void
    {
        $link = $this->link(['create', 'update']);
        $element = $this->createMock(ElementInterface::class);

        $this->assertSame(SyncDecision::UPDATE, SyncDecision::decide($link, 42, $element));
    }

    public function testExistingElementSkipsWhenUpdateDisabled(): void
    {
        $link = $this->link(['create']);
        $element = $this->createMock(ElementInterface::class);

        $this->assertSame(SyncDecision::SKIP_NO_UPDATE, SyncDecision::decide($link, 42, $element));
    }

    /**
     * @param list<string> $processing
     */
    private function link(array $processing = ['create', 'update']): Link
    {
        $link = new Link();
        $link->handle = 'articles';
        $link->name = 'Articles';
        $link->elementType = Entry::class;
        $link->processing = $processing;

        return $link;
    }
}
