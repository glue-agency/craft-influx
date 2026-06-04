<?php

namespace TDM\Influx\services;

use craft\base\Component;
use TDM\Influx\auth\AuthStrategyInterface;
use TDM\Influx\auth\BearerAuth;
use TDM\Influx\auth\CustomHeaderAuth;
use TDM\Influx\auth\QueryStringAuth;
use TDM\Influx\events\RegisterAuthTypesEvent;
use TDM\Influx\exceptions\InfluxException;

/**
 * Registry of auth strategies. Mirrors the shape of {@see TargetsService} and
 * {@see FieldsService} — built-ins are seeded into the registration event
 * payload before triggering, so listeners can append, override, or remove
 * strategies, not just append.
 *
 *   Event::on(
 *       AuthService::class,
 *       AuthService::EVENT_REGISTER_AUTH_TYPES,
 *       function (RegisterAuthTypesEvent $event) {
 *           $event->authTypes[] = MyHmacAuth::class;
 *       }
 *   );
 *
 * Strategies are keyed by their {@see AuthStrategyInterface::type()}; a new
 * registration under the same key replaces whatever was there.
 */
class AuthService extends Component
{
    public const EVENT_REGISTER_AUTH_TYPES = 'registerAuthTypes';

    /** @var array<string, class-string<AuthStrategyInterface>> Strategy class by type. */
    private array $strategies = [];

    private bool $initialized = false;

    /**
     * Built-ins shipped with the plugin. Exposed as a method so tests and
     * subclasses can override the default set.
     *
     * @return list<class-string<AuthStrategyInterface>>
     */
    protected function defaultStrategies(): array
    {
        return [
            BearerAuth::class,
            CustomHeaderAuth::class,
            QueryStringAuth::class,
        ];
    }

    /**
     * Direct registration. Forces the registration event to fire first
     * (seeding built-ins) so callers can rely on overriding built-ins by
     * simply re-registering them — the explicit call always wins over the
     * defaults regardless of timing.
     *
     * @param class-string<AuthStrategyInterface> $class
     */
    public function register(string $class): void
    {
        $this->ensureLoaded();
        $this->registerOne($class);
    }

    private function registerOne(string $class): void
    {
        if (!is_subclass_of($class, AuthStrategyInterface::class)) {
            throw new InfluxException("'{$class}' must implement " . AuthStrategyInterface::class . '.');
        }
        $this->strategies[$class::type()] = $class;
    }

    /**
     * @return list<string>
     */
    public function knownTypes(): array
    {
        $this->ensureLoaded();
        return array_keys($this->strategies);
    }

    /**
     * Build a strategy for the link's auth config, or null when no auth is
     * configured. Returns null for an unknown `type` too — validation on the
     * Link model is what reports that as an error.
     */
    public function fromConfig(array $config): ?AuthStrategyInterface
    {
        $this->ensureLoaded();

        $type = $config['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return null;
        }
        $class = $this->strategies[$type] ?? null;
        if (!$class) {
            return null;
        }
        return new $class($config);
    }

    private function ensureLoaded(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $event = new RegisterAuthTypesEvent(['authTypes' => $this->defaultStrategies()]);
        $this->trigger(self::EVENT_REGISTER_AUTH_TYPES, $event);

        foreach ($event->authTypes as $class) {
            $this->registerOne($class);
        }
    }
}
