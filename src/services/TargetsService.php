<?php

namespace GlueAgency\Influx\services;

use GlueAgency\Influx\events\RegisterTargetsEvent;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AssetTarget;
use GlueAgency\Influx\targets\CategoryTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\targets\GlobalSetTarget;
use GlueAgency\Influx\targets\TagTarget;
use GlueAgency\Influx\targets\UserTarget;

/**
 * Registry of element-target adapters, keyed by the element-type FQCN each one
 * declares. One per native Craft element type ships built-in; they're seeded into
 * the registration event payload before triggering, so listeners can append new
 * targets, override built-ins (by re-adding under the same element type), or
 * remove them entirely — see {@see AbstractRegistry} for the shared mechanics.
 *
 *   Event::on(
 *       TargetsService::class,
 *       TargetsService::EVENT_REGISTER_TARGETS,
 *       function (RegisterTargetsEvent $event) {
 *           $event->targets[] = MyCalendarEventTarget::class;
 *       }
 *   );
 */
class TargetsService extends AbstractRegistry
{
    public const EVENT_REGISTER_TARGETS = 'registerTargets';

    public function forLink(Link $link): ?ElementTargetInterface
    {
        return $this->item($link->elementType);
    }

    /**
     * Human-readable label for an element-type FQCN. Falls back to the class's
     * short name when no target is registered for it.
     */
    public function friendlyNameFor(string $elementType): string
    {
        $target = $this->item($elementType);

        if ($target) {
            return $target::friendlyName();
        }
        $parts = explode('\\', $this->normalizeKey($elementType));

        return end($parts) ?: $elementType;
    }

    /**
     * @return list<class-string<ElementTargetInterface>>
     */
    protected function defaults(): array
    {
        return [
            AssetTarget::class,
            CategoryTarget::class,
            EntryTarget::class,
            GlobalSetTarget::class,
            TagTarget::class,
            UserTarget::class,
        ];
    }

    protected function itemType(): string
    {
        return ElementTargetInterface::class;
    }

    protected function keyFor(object $item): string
    {
        return $item::elementType();
    }

    protected function eventName(): string
    {
        return self::EVENT_REGISTER_TARGETS;
    }

    protected function eventClass(): string
    {
        return RegisterTargetsEvent::class;
    }
}
