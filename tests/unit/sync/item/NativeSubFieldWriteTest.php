<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeElement;
use ReflectionClass;

/**
 * The `nativeFields` channel: one value written straight onto a related element's
 * own attribute — an asset's alt text, a related entry's title, a user's email.
 *
 * The guard in front of that write asked `$element->hasAttribute()`, which is an
 * ActiveRecord method. `yii\base\Model` doesn't have it, so on every element the
 * guard threw `UnknownMethodException` instead of answering, and the sub-mapping
 * loop deliberately doesn't catch — so every native sub-field on every
 * relational strategy failed its row with "Calling unknown method". This suite
 * exists because that had no coverage at all: the specs below name the real
 * element classes, so the question "does this element have this attribute" is
 * asked of Craft rather than of a stand-in that answers however it likes.
 */
class NativeSubFieldWriteTest extends Unit
{
    /**
     * The attributes the builder's cards actually offer, per element type.
     *
     * @return iterable<string, array{0: class-string<ElementInterface>, 1: string}>
     */
    public static function offeredAttributes(): iterable
    {
        yield 'an asset\'s alt text' => [Asset::class, 'alt'];
        yield 'an asset\'s title' => [Asset::class, 'title'];
        yield 'a related entry\'s title' => [Entry::class, 'title'];
        yield 'a related entry\'s slug' => [Entry::class, 'slug'];
        yield 'a user\'s email' => [User::class, 'email'];
        yield 'a user\'s username' => [User::class, 'username'];
        yield 'a user\'s full name' => [User::class, 'fullName'];
    }

    /**
     * @dataProvider offeredAttributes
     * @param class-string<ElementInterface> $elementType
     */
    public function testEveryOfferedAttributeIsWritable(string $elementType, string $attribute): void
    {
        // Not a behavioural assertion about the applier — an assertion about
        // Craft. If one of these ever stops being a settable property, the card
        // that offers it is lying and this fails at the source.
        //
        // Read off the class rather than an instance: constructing a real
        // element needs a booted Craft (CustomFieldBehavior is generated at
        // runtime), and this mirrors exactly what `canSetProperty($n, true,
        // false)` asks — a public, non-static property or a setter.
        $class = new ReflectionClass($elementType);
        $property = $class->hasProperty($attribute) ? $class->getProperty($attribute) : null;

        $settable = ($property !== null && $property->isPublic() && ! $property->isStatic())
            || $class->hasMethod('set' . ucfirst($attribute));

        $this->assertTrue(
            $settable,
            "{$elementType}::\${$attribute} is offered as a native sub-field but isn't settable.",
        );
    }

    public function testACustomFieldHandleIsNotTakenAsANativeAttribute(): void
    {
        // Behaviours are excluded from the guard on purpose: with them, a custom
        // field would answer true through Craft's CustomFieldBehavior and get
        // assigned onto the element, bypassing the `fields` channel that resolves
        // it through the layout and its own strategy.
        $this->assertNull($this->row('someCustomFieldHandle', ['x' => 'y']));
    }

    public function testTheValueLandsOnTheAttributeAndIsReported(): void
    {
        $element = new class() extends FakeElement {
            public ?string $alt = null;
        };

        $row = $this->row('alt', ['caption' => 'Een röntgenfoto'], $element, ['node' => 'caption']);

        $this->assertInstanceOf(MappingResult::class, $row);
        $this->assertSame('Een röntgenfoto', $element->alt);
        $this->assertSame('Een röntgenfoto', $row->parsedValue);
        $this->assertTrue($row->changed);
    }

    public function testRewritingTheSameValueIsNoChange(): void
    {
        $element = new class() extends FakeElement {
            public ?string $alt = 'Een röntgenfoto';
        };

        $row = $this->row('alt', ['caption' => 'Een röntgenfoto'], $element, ['node' => 'caption']);

        $this->assertFalse($row->changed);
        $this->assertSame('Een röntgenfoto', $element->alt);
    }

    public function testAnUnaddressedSubFieldLeavesTheAttributeAlone(): void
    {
        $element = new class() extends FakeElement {
            public ?string $alt = 'Stored';
        };

        $row = $this->row('alt', ['nothing' => 'here'], $element, ['node' => 'caption']);

        $this->assertTrue($row->unaddressed);
        $this->assertSame('Stored', $element->alt, 'An unaddressed sub-field must not clear the attribute.');
    }

    /**
     * One native sub-mapping, applied through the real applier.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $config
     */
    protected function row(
        string $handle,
        array $item,
        ?ElementInterface $element = null,
        array $config = ['node' => 'caption'],
    ): ?MappingResult {
        $applier = new class() extends MappingApplier {
            public function exposedApplyNativeSubField(
                ElementInterface $element,
                RemoteItem $item,
                FieldMapping $sub,
            ): ?MappingResult {
                return $this->applyNativeSubField($element, $item, $sub);
            }
        };

        return $applier->exposedApplyNativeSubField(
            $element ?? new FakeElement(),
            new RemoteItem($item),
            FieldMapping::fromConfig($handle, $config),
        );
    }
}
