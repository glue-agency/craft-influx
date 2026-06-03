<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\Link;
use TDM\Influx\targets\ElementTargetInterface;
use yii\base\Event;

/**
 * Registry of element-target adapters. Built-ins are added by the plugin
 * during init; third-party plugins listen to EVENT_REGISTER_TARGETS or call
 * register() directly.
 */
class TargetsService extends Component
{
    /**
     * Event for registering additional targets. Push FQCNs onto $event->types.
     */
    public const EVENT_REGISTER_TARGETS = 'registerTargets';

    /** @var ElementTargetInterface[] keyed by elementType FQCN */
    private array $targets = [];

    private bool $eventFired = false;

    /**
     * @param class-string<ElementTargetInterface> $class
     */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, ElementTargetInterface::class)) {
            throw new InfluxException("'{$class}' must implement ElementTargetInterface.");
        }
        $target = Craft::createObject($class);
        $this->targets[ltrim($class::elementType(), '\\')] = $target;
    }

    /**
     * @return ElementTargetInterface[]
     */
    public function all(): array
    {
        $this->fireRegistrationEventOnce();
        return $this->targets;
    }

    public function forLink(Link $link): ?ElementTargetInterface
    {
        $this->fireRegistrationEventOnce();
        return $this->targets[ltrim($link->elementType, '\\')] ?? null;
    }

    private function fireRegistrationEventOnce(): void
    {
        if ($this->eventFired) {
            return;
        }
        $this->eventFired = true;

        if ($this->hasEventHandlers(self::EVENT_REGISTER_TARGETS)) {
            $event = new \craft\events\RegisterComponentTypesEvent();
            $this->trigger(self::EVENT_REGISTER_TARGETS, $event);
            foreach ($event->types as $class) {
                $this->register($class);
            }
        }
    }
}
