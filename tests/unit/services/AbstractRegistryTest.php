<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use GlueAgency\Influx\events\RegisterEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\services\AbstractRegistry;
use yii\base\Event;

/**
 * The registration contract every Influx registry (fields / targets / auth)
 * inherits, specced once through a throwaway registry:
 *
 *   - resolution is lazy and happens exactly once, with the built-ins seeded
 *     INTO the event payload so a listener sees (and can edit) them;
 *   - listeners may append, replace by key, or drop a built-in;
 *   - registered classes become shared prototype instances, keyed by the
 *     discriminator the class declares, leading backslashes normalised;
 *   - a class that doesn't satisfy the registry's item type throws;
 *   - register() is the imperative equivalent and always wins over defaults.
 */
class AbstractRegistryTest extends Unit
{
    protected function tearDown(): void
    {
        Event::off(WidgetRegistry::class, WidgetRegistry::EVENT_REGISTER_WIDGETS);

        parent::tearDown();
    }

    public function testSeedsTheBuiltInsIntoTheEventPayload(): void
    {
        $seen = null;
        Event::on(
            WidgetRegistry::class,
            WidgetRegistry::EVENT_REGISTER_WIDGETS,
            function(RegisterWidgetsEvent $event) use (&$seen) {
                $seen = $event->widgets;
            },
        );

        $registry = new WidgetRegistry();
        $all = $registry->all();

        $this->assertSame([AlphaWidget::class, BetaWidget::class], $seen);
        $this->assertSame(['alpha', 'beta'], array_keys($all));
        $this->assertInstanceOf(AlphaWidget::class, $all['alpha']);
    }

    public function testResolvesLazilyAndOnlyOnce(): void
    {
        $fired = 0;
        Event::on(
            WidgetRegistry::class,
            WidgetRegistry::EVENT_REGISTER_WIDGETS,
            function() use (&$fired) {
                $fired++;
            },
        );

        $registry = new WidgetRegistry();
        $this->assertSame(0, $fired, 'Constructing a registry must not fire its registration event.');

        $registry->all();
        $registry->all();
        $registry->find('alpha');

        $this->assertSame(1, $fired);
    }

    public function testHandsOutSharedPrototypes(): void
    {
        $registry = new WidgetRegistry();

        $this->assertSame($registry->find('alpha'), $registry->find('alpha'));
    }

    public function testListenersCanAppendAndOverrideByKey(): void
    {
        Event::on(
            WidgetRegistry::class,
            WidgetRegistry::EVENT_REGISTER_WIDGETS,
            function(RegisterWidgetsEvent $event) {
                // Same key as the built-in — replaces it; the other is new.
                $event->widgets[] = AlphaOverrideWidget::class;
                $event->widgets[] = GammaWidget::class;
            },
        );

        $registry = new WidgetRegistry();

        $this->assertInstanceOf(AlphaOverrideWidget::class, $registry->find('alpha'));
        $this->assertInstanceOf(GammaWidget::class, $registry->find('gamma'));
        $this->assertSame(['alpha', 'beta', 'gamma'], array_keys($registry->all()));
    }

    public function testListenersCanRemoveABuiltIn(): void
    {
        Event::on(
            WidgetRegistry::class,
            WidgetRegistry::EVENT_REGISTER_WIDGETS,
            function(RegisterWidgetsEvent $event) {
                $event->widgets = array_values(array_filter(
                    $event->widgets,
                    static fn(string $class): bool => $class !== BetaWidget::class,
                ));
            },
        );

        $registry = new WidgetRegistry();

        $this->assertNull($registry->find('beta'));
        $this->assertSame(['alpha'], array_keys($registry->all()));
    }

    public function testNormalisesLeadingBackslashesOnBothSides(): void
    {
        Event::on(
            WidgetRegistry::class,
            WidgetRegistry::EVENT_REGISTER_WIDGETS,
            function(RegisterWidgetsEvent $event) {
                $event->widgets[] = BackslashedWidget::class;
            },
        );

        $registry = new WidgetRegistry();

        $this->assertInstanceOf(BackslashedWidget::class, $registry->find('Vendor\Delta'));
        $this->assertInstanceOf(BackslashedWidget::class, $registry->find('\Vendor\Delta'));
        $this->assertInstanceOf(AlphaWidget::class, $registry->find('\alpha'));
    }

    public function testRejectsAClassOutsideTheRegistrysItemType(): void
    {
        $registry = new WidgetRegistry();

        $this->expectException(InfluxException::class);
        $registry->register(self::class);
    }

    public function testImperativeRegisterSeedsTheDefaultsFirstAndWins(): void
    {
        $registry = new WidgetRegistry();
        $registry->register(AlphaOverrideWidget::class);

        // The built-ins were seeded by the forced event, so beta survives...
        $this->assertInstanceOf(BetaWidget::class, $registry->find('beta'));
        // ...while the explicit registration beat the default under its key.
        $this->assertInstanceOf(AlphaOverrideWidget::class, $registry->find('alpha'));
    }
}

/** @internal The item contract of the throwaway registry below. */
interface WidgetInterface
{
    public static function key(): string;
}

/** @internal */
class AlphaWidget implements WidgetInterface
{
    public static function key(): string
    {
        return 'alpha';
    }
}

/** @internal */
class BetaWidget implements WidgetInterface
{
    public static function key(): string
    {
        return 'beta';
    }
}

/** @internal Registers under the same key as {@see AlphaWidget}. */
class AlphaOverrideWidget implements WidgetInterface
{
    public static function key(): string
    {
        return 'alpha';
    }
}

/** @internal */
class GammaWidget implements WidgetInterface
{
    public static function key(): string
    {
        return 'gamma';
    }
}

/** @internal Declares an FQCN-shaped key with a leading backslash. */
class BackslashedWidget implements WidgetInterface
{
    public static function key(): string
    {
        return '\Vendor\Delta';
    }
}

/** @internal */
class RegisterWidgetsEvent extends RegisterEvent
{
    /** @var class-string<WidgetInterface>[] */
    public array $widgets = [];

    public function seed(array $classes): void
    {
        $this->widgets = $classes;
    }

    public function registered(): array
    {
        return $this->widgets;
    }
}

/** @internal Minimal conforming registry: five hooks plus a lookup. */
class WidgetRegistry extends AbstractRegistry
{
    public const EVENT_REGISTER_WIDGETS = 'registerWidgets';

    public function find(string $key): ?WidgetInterface
    {
        return $this->item($key);
    }

    protected function defaults(): array
    {
        return [AlphaWidget::class, BetaWidget::class];
    }

    protected function itemType(): string
    {
        return WidgetInterface::class;
    }

    protected function keyFor(object $item): string
    {
        return $item::key();
    }

    protected function eventName(): string
    {
        return self::EVENT_REGISTER_WIDGETS;
    }

    protected function eventClass(): string
    {
        return RegisterWidgetsEvent::class;
    }
}
