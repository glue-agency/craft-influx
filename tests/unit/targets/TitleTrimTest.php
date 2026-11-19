<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\CategoryTarget;
use GlueAgency\Influx\targets\EntryTarget;
use ReflectionClass;

/**
 * A title is compared and written trimmed, as Craft's element rules store it:
 * a feed sending "Voltijd " against a stored "Voltijd" must not read as a change,
 * or the element re-saves on every run.
 */
class TitleTrimTest extends Unit
{
    public function testACategoryTitleWithSurroundingWhitespaceIsNoChange(): void
    {
        $category = $this->element(Category::class, 'Voltijd');

        $this->assertFalse($this->parse(new CategoryTarget(), $category, ['description' => 'Voltijd ']));
        $this->assertSame('Voltijd', $category->title);
    }

    public function testAnEntryTitleIsTrimmedBeforeItIsTruncated(): void
    {
        $entry = $this->element(Entry::class, 'Old');

        $this->assertTrue($this->parse(new EntryTarget(), $entry, ['description' => "  New title\n"]));
        $this->assertSame('New title', $entry->title);
    }

    public function testANullTitleClearsIt(): void
    {
        $category = $this->element(Category::class, 'Voltijd');

        $this->assertTrue($this->parse(new CategoryTarget(), $category, ['description' => null]));
        $this->assertNull($category->title);
    }

    private function parse(object $target, ElementInterface $element, array $feed): bool
    {
        $method = (new ReflectionClass($target))->getMethod('parseTitle');

        return $method->invoke(
            $target,
            $this->createMock(SyncContext::class),
            $element,
            new RemoteItem($feed),
            FieldMapping::fromConfig('title', ['node' => 'description']),
        );
    }

    /**
     * @template T of ElementInterface
     * @param class-string<T> $class
     * @return T
     */
    private function element(string $class, string $title): ElementInterface
    {
        $element = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $element->title = $title;

        return $element;
    }
}
