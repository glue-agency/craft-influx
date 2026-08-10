<?php

namespace GlueAgency\Influx\services;

use GlueAgency\Influx\events\RegisterTargetsEvent;
use GlueAgency\Influx\integrations\solspace\calendar\EventTarget;
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
 * declares. One per native Craft element type ships built-in, plus targets for
 * the third-party element types Influx supports out of the box
 * ({@see EventTarget}) — those go inert on a site without the plugin, see
 * {@see defaults()}. They're seeded into the registration event payload before
 * triggering, so listeners can append new targets, override built-ins (by
 * re-adding under the same element type), or remove them entirely — see
 * {@see AbstractRegistry} for the shared mechanics.
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
     * The built-ins, minus any whose element type isn't installed
     * ({@see ElementTargetInterface::isAvailable()}) — which is what lets a target
     * for a third-party element type ship in the box. An unavailable one is
     * dropped before registration rather than filtered at every read, so nothing
     * downstream has to know the concept exists.
     *
     * Only the DEFAULTS are filtered. A target arriving through
     * {@see EVENT_REGISTER_TARGETS} or {@see register()} is registered as asked:
     * a listener adding one has its own plugin loaded by definition, and silently
     * dropping a third party's registration would be the harder failure to find.
     *
     * @return list<class-string<ElementTargetInterface>>
     */
    protected function defaults(): array
    {
        $targets = [
            AssetTarget::class,
            CategoryTarget::class,
            EntryTarget::class,
            EventTarget::class,
            GlobalSetTarget::class,
            TagTarget::class,
            UserTarget::class,
        ];

        return array_values(array_filter($targets, fn(string $target) => $target::isAvailable()));
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
