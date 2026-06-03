<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\mappings\MappingInterface;
use TDM\Influx\mappings\PlainTextMapping;

/**
 * Registry of mapping types. Third parties can add mappings either by
 * calling register() directly or via the EVENT_REGISTER_MAPPINGS event.
 */
class MappingService extends Component
{
    public const EVENT_REGISTER_MAPPINGS = 'registerMappings';

    /** @var MappingInterface[] keyed by type() */
    private array $mappings = [];

    private bool $initialized = false;

    public function init(): void
    {
        parent::init();

        $this->registerClass(PlainTextMapping::class);
    }

    /**
     * @param class-string<MappingInterface> $class
     */
    public function registerClass(string $class): void
    {
        if (!is_subclass_of($class, MappingInterface::class)) {
            throw new InfluxException("'{$class}' must implement MappingInterface.");
        }
        $instance = Craft::createObject($class);
        $this->mappings[$class::type()] = $instance;
    }

    /**
     * @return MappingInterface[]
     */
    public function all(): array
    {
        $this->ensureExternalsRegistered();
        return $this->mappings;
    }

    public function get(string $type): MappingInterface
    {
        $this->ensureExternalsRegistered();

        if (!isset($this->mappings[$type])) {
            throw new InfluxException("Unknown mapping type '{$type}'.");
        }
        return $this->mappings[$type];
    }

    private function ensureExternalsRegistered(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        if ($this->hasEventHandlers(self::EVENT_REGISTER_MAPPINGS)) {
            $event = new RegisterComponentTypesEvent();
            $this->trigger(self::EVENT_REGISTER_MAPPINGS, $event);
            foreach ($event->types as $class) {
                $this->registerClass($class);
            }
        }
    }
}
