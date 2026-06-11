<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\events\RegisterTargetsEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\targets\EntryTarget;

/**
 * Registry of element-target adapters. Built-ins are seeded into the
 * registration event payload before triggering, so listeners can append new
 * targets, override built-ins (by re-adding under the same element type), or
 * remove them entirely.
 *
 *   Event::on(
 *       TargetsService::class,
 *       TargetsService::EVENT_REGISTER_TARGETS,
 *       function (RegisterTargetsEvent $event) {
 *           $event->targets[] = MyCalendarEventTarget::class;
 *       }
 *   );
 *
 * Targets are keyed by their {@see ElementTargetInterface::elementType()};
 * a new registration under the same key replaces whatever was there.
 */
class TargetsService extends Component
{
    public const EVENT_REGISTER_TARGETS = 'registerTargets';

    /** @var ElementTargetInterface[] keyed by elementType FQCN */
    protected array $targets = [];

    protected bool $initialized = false;

    /**
     * Built-ins shipped with the plugin. Exposed as a method so tests and
     * subclasses can override the default set.
     *
     * @return list<class-string<ElementTargetInterface>>
     */
    protected function defaultTargets(): array
    {
        return [
            EntryTarget::class,
        ];
    }

    /**
     * Direct registration. Forces the registration event to fire first
     * (seeding built-ins) so callers can rely on overriding built-ins by
     * simply re-registering them — the explicit call always wins over the
     * defaults regardless of timing.
     *
     * @param class-string<ElementTargetInterface> $class
     */
    public function register(string $class): void
    {
        $this->ensureLoaded();
        $this->registerOne($class);
    }

    protected function registerOne(string $class): void
    {
        if (!is_subclass_of($class, ElementTargetInterface::class)) {
            throw new InfluxException("'{$class}' must implement " . ElementTargetInterface::class . '.');
        }
        $target = Craft::createObject($class);
        $this->targets[ltrim($class::elementType(), '\\')] = $target;
    }

    /**
     * @return ElementTargetInterface[]
     */
    public function all(): array
    {
        $this->ensureLoaded();
        return $this->targets;
    }

    public function forLink(Link $link): ?ElementTargetInterface
    {
        $this->ensureLoaded();
        return $this->targets[ltrim($link->elementType, '\\')] ?? null;
    }

    /**
     * Human-readable label for an element-type FQCN. Falls back to the class's
     * short name when no target is registered for it.
     */
    public function friendlyNameFor(string $elementType): string
    {
        $this->ensureLoaded();
        $key = ltrim($elementType, '\\');
        if (isset($this->targets[$key])) {
            return $this->targets[$key]::friendlyName();
        }
        $parts = explode('\\', $key);
        return end($parts) ?: $elementType;
    }

    protected function ensureLoaded(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $event = new RegisterTargetsEvent(['targets' => $this->defaultTargets()]);
        $this->trigger(self::EVENT_REGISTER_TARGETS, $event);

        foreach ($event->targets as $class) {
            $this->registerOne($class);
        }
    }
}
