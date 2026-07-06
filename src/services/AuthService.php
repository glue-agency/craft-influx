<?php

namespace GlueAgency\Influx\services;

use craft\base\Component;
use GlueAgency\Influx\auth\AuthStrategyInterface;
use GlueAgency\Influx\auth\BasicAuth;
use GlueAgency\Influx\auth\BearerAuth;
use GlueAgency\Influx\auth\CustomHeaderAuth;
use GlueAgency\Influx\auth\QueryStringAuth;
use GlueAgency\Influx\events\RegisterAuthTypesEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;

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
    protected array $strategies = [];

    protected bool $initialized = false;

    /**
     * Built-ins shipped with the plugin. Exposed as a method so tests and
     * subclasses can override the default set.
     *
     * @return list<class-string<AuthStrategyInterface>>
     */
    protected function defaultStrategies(): array
    {
        return [
            BasicAuth::class,
            BearerAuth::class,
            CustomHeaderAuth::class,
            QueryStringAuth::class,
        ];
    }

    protected function registerOne(string $class): void
    {
        if (! is_subclass_of($class, AuthStrategyInterface::class)) {
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
     * Registered strategies keyed by type. Used by the link edit screen to
     * derive the dropdown options and the per-type form partials from the
     * same source of truth — no hardcoded list in the controller.
     *
     * @return array<string, class-string<AuthStrategyInterface>>
     */
    public function strategies(): array
    {
        $this->ensureLoaded();

        return $this->strategies;
    }

    /**
     * Mutate the given header / query arrays to add the link's auth
     * credentials. The actual rule per auth type lives on the strategy classes
     * in {@see \GlueAgency\Influx\auth}, dispatched via {@see fromConfig()}.
     *
     * Moved here from {@see Link::applyAuth()} — the model still owns the auth
     * config, but building and applying the strategy is this service's job.
     *
     * @throws InfluxException when auth is configured but its type no longer
     * resolves (e.g. a third-party strategy was unregistered after the link
     * was saved) — fail loudly rather than fire the request unauthenticated.
     */
    public function applyToRequest(Link $link, array &$headers, array &$query): void
    {
        if (empty($link->auth)) {
            return;
        }

        $strategy = $this->fromConfig($link->auth);

        if (! $strategy) {
            throw new InfluxException(
                "Link '{$link->handle}' has an unresolvable auth type '" . ($link->auth['type'] ?? '?') . "'.",
            );
        }

        $strategy->apply($headers, $query);
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

        if (! is_string($type) || $type === '') {
            return null;
        }
        $class = $this->strategies[$type] ?? null;

        if (! $class) {
            return null;
        }

        return new $class($config);
    }

    protected function ensureLoaded(): void
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
