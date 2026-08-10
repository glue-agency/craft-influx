<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Category;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Behaviour spec for the target members every element type shares, exercised
 * through a minimal concrete target (no section/type dimension, so it inherits
 * the base's structural targeting too):
 *
 *   - isAvailable() answers from the declared element class alone, so a target
 *     for an uninstalled plugin drops out of the registry without declaring
 *     anything;
 *   - claimsElement() = structurally targeted PLUS a non-empty match value;
 *   - parseEnabled() is reached by the `parse{Handle}` dispatch even though it
 *     lives on the base — applyNativeAttribute()'s method_exists() lookup sees
 *     inherited parsers.
 *
 * Elements are anonymous Element subclasses with a skipped constructor, so the
 * spec runs without a booted Craft.
 */
class AbstractElementTargetTest extends Unit
{
    public function testAvailabilityFollowsTheDeclaredElementClass(): void
    {
        // The whole of what a third-party target has to declare about its own
        // installability: a present element class is available, an absent one is
        // inert — and asking about a class that isn't there has to ANSWER rather
        // than throw, since the registry asks before anything is loaded.
        $this->assertTrue($this->targetFor(Category::class)::isAvailable());
        $this->assertFalse($this->targetFor('vendor\\nowhere\\Widget')::isAvailable());
        // "Is a Craft element", not merely "class exists".
        $this->assertFalse($this->targetFor(RuntimeException::class)::isAvailable());
    }

    public function testClaimsElementRequiresAMatchValueOnTopOfTargeting(): void
    {
        $target = $this->target();
        $link = FakeLink::make([
            'elementType' => ElementInterface::class,
            'match'       => ['attribute' => 'importId'],
        ]);

        $withValue = $this->element('abc');
        $this->assertTrue($target->targetsElement($link, $withValue));
        $this->assertTrue($target->claimsElement($link, $withValue));

        $withoutValue = $this->element(null);
        $this->assertTrue($target->targetsElement($link, $withoutValue));
        $this->assertFalse($target->claimsElement($link, $withoutValue));

        $empty = $this->element('');
        $this->assertTrue($target->targetsElement($link, $empty));
        $this->assertFalse($target->claimsElement($link, $empty));
    }

    public function testClaimsElementIsFalseWithoutAMatchAttribute(): void
    {
        $target = $this->target();
        $link = FakeLink::make(['elementType' => ElementInterface::class, 'match' => []]);

        $element = $this->element('abc');
        $this->assertTrue($target->targetsElement($link, $element));
        $this->assertFalse(
            $target->claimsElement($link, $element),
            'A link with no match attribute can never pair a feed item with an element.',
        );
    }

    public function testAnUnhandledLinkClaimsNothing(): void
    {
        $target = $this->target();
        $link = FakeLink::make(['elementType' => 'craft\\elements\\Asset', 'match' => ['attribute' => 'importId']]);

        $element = $this->element('abc');
        $this->assertFalse($target->targetsElement($link, $element));
        $this->assertFalse($target->claimsElement($link, $element));
    }

    public function testEnabledIsCoercedByTheInheritedParser(): void
    {
        $target = $this->target();
        $element = $this->element('abc');
        $element->enabled = true;

        $this->assertTrue(
            $this->applyEnabled($target, $element, ['featured' => 'no']),
            'A truthy-to-falsey flip is a change.',
        );
        $this->assertFalse($element->enabled);

        $this->assertTrue($this->applyEnabled($target, $element, ['featured' => 'YES']));
        $this->assertTrue($element->enabled);

        $this->assertFalse(
            $this->applyEnabled($target, $element, ['featured' => '1']),
            'Re-applying the same flag is not a change.',
        );
        $this->assertTrue($element->enabled);

        $this->assertTrue(
            $this->applyEnabled($target, $element, []),
            'An addressed-but-empty value coerces to false, i.e. disabled.',
        );
        $this->assertFalse($element->enabled);
    }

    protected function applyEnabled(AbstractElementTarget $target, ElementInterface $element, array $feed): bool
    {
        $link = FakeLink::make(['elementType' => ElementInterface::class]);

        return $target->applyNativeAttribute(
            new SyncContext(link: $link, target: $target),
            $element,
            'enabled',
            new RemoteItem($feed),
            FieldMapping::fromConfig('enabled', ['node' => 'featured']),
        );
    }

    /**
     * A target for "any element" — the abstract contract only, so every shared
     * member under test is the base's own.
     */
    protected function target(): AbstractElementTarget
    {
        return new class() extends AbstractElementTarget {
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
    }

    /** A target declaring the given element type and nothing else. */
    protected function targetFor(string $elementType): AbstractElementTarget
    {
        $target = new class() extends AbstractElementTarget {
            public static string $type = '';

            public static function elementType(): string
            {
                return static::$type;
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
        $target::$type = $elementType;

        return $target;
    }

    protected function element(mixed $match): Element
    {
        $element = new class() extends Element {
            public mixed $importId = null;

            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };
        $element->importId = $match;

        return $element;
    }
}
