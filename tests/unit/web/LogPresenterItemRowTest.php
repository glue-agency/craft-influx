<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\Tests\unit\Support\FakeElement;
use GlueAgency\Influx\web\LogPresenter;

/**
 * Behaviour spec for {@see LogPresenter::presentItem()} — the item-list row the
 * log viewer renders: which source wins the row's title, and the `trashed` flag
 * the list pills an element by.
 *
 * Bootless: every case passes an element map, which is the branch that never
 * reaches for `Craft::$app` (the null-map branch loads on demand and needs a
 * booted app). The map is exactly what {@see LogPresenter::elementMap()} builds,
 * trashed elements included — a soft-deleted element resolves, so the row keeps
 * showing it, and only a hard-deleted one is absent.
 */
class LogPresenterItemRowTest extends Unit
{
    protected LogPresenter $presenter;

    protected function _before(): void
    {
        $this->presenter = new LogPresenter();
    }

    public function testAResolvedElementTitlesTheRowAndIsntFlagged(): void
    {
        $element = $this->element(512, 'Werfkelder');

        $row = $this->presenter->presentItem(
            $this->item(['elementId' => 512, 'matchValue' => 'abc']),
            [512 => $element],
        );

        $this->assertSame('Werfkelder', $row['title']);
        $this->assertFalse($row['trashed']);
    }

    /**
     * The plugin deletes softly, so a run's deleted rows point at elements that
     * are still there — in the Trash. They keep their label, and say where they
     * are.
     */
    public function testATrashedElementKeepsItsTitleAndIsFlagged(): void
    {
        $element = $this->element(512, 'Werfkelder');
        $element->trashed = true;

        $row = $this->presenter->presentItem(
            $this->item(['elementId' => 512, 'action' => ItemAction::DELETED->value]),
            [512 => $element],
        );

        $this->assertSame('Werfkelder', $row['title']);
        $this->assertTrue($row['trashed']);
    }

    /**
     * A resolved element titles the row even when it has no title of its own —
     * Craft's own label for it beats the match value, which is a feed-side
     * identifier.
     */
    public function testAResolvedElementWinsOverTheMatchValue(): void
    {
        $row = $this->presenter->presentItem(
            $this->item(['elementId' => 512, 'matchValue' => 'abc']),
            [512 => $this->element(512, '')],
        );

        $this->assertNotSame('abc', $row['title']);
        $this->assertStringContainsString('512', $row['title']);
    }

    /**
     * A hard-deleted element: the match value names the feed item the row
     * processed, which is worth more to a reader than an `#id` pointing at
     * nothing.
     */
    public function testAnUnresolvableElementPrefersTheMatchValue(): void
    {
        $row = $this->presenter->presentItem(
            $this->item(['elementId' => 512, 'matchValue' => 'abc']),
            [],
        );

        $this->assertSame('abc', $row['title']);
        $this->assertFalse($row['trashed']);
    }

    public function testAnUnresolvableElementWithoutAMatchValueShowsItsElementId(): void
    {
        $row = $this->presenter->presentItem($this->item(['elementId' => 512]), []);

        $this->assertSame('#512', $row['title']);
    }

    public function testARowWithNoElementAndNoMatchValueShowsItsOwnId(): void
    {
        $row = $this->presenter->presentItem($this->item(['id' => 88]), []);

        $this->assertSame('#88', $row['title']);
        $this->assertFalse($row['trashed']);
    }

    public function testTheRowCountsTheStoredFieldErrors(): void
    {
        $row = $this->presenter->presentItem($this->item([
            'action'      => ItemAction::UPDATED->value,
            'matchValue'  => 'abc',
            'message'     => 'Saved with errors.',
            'fieldErrors' => '{"summary":"Too long","body":"Bad HTML"}',
        ]), []);

        $this->assertSame(2, $row['errorCount']);
        $this->assertSame(ItemAction::UPDATED->value, $row['action']);
        $this->assertSame('abc', $row['matchValue']);
        $this->assertSame('Saved with errors.', $row['message']);
    }

    protected function element(int $id, string $title): ElementInterface
    {
        $element = new FakeElement();
        $element->id = $id;
        $element->title = $title;

        return $element;
    }

    /**
     * A log-item record standing in for a stored one: attribute reads/writes go
     * to a plain array instead of the table schema, which keeps the spec free of
     * a database (mirroring StoredLogItemDrillDownTest's records).
     *
     * @param array<string, mixed> $values
     */
    protected function item(array $values): LogItemRecord
    {
        $item = new class() extends LogItemRecord {
            /** @var array<string, mixed> */
            public array $attrs = [];

            public function __get($name)
            {
                return $this->attrs[$name] ?? null;
            }

            public function __set($name, $value)
            {
                $this->attrs[$name] = $value;
            }
        };
        $item->attrs = $values + ['id' => 1, 'logId' => 1, 'action' => ItemAction::UPDATED->value];

        return $item;
    }
}
