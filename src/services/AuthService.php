<?php

namespace GlueAgency\Influx\services;

use Craft;
use GlueAgency\Influx\auth\AuthStrategyInterface;
use GlueAgency\Influx\auth\BasicAuth;
use GlueAgency\Influx\auth\BearerAuth;
use GlueAgency\Influx\auth\CustomHeaderAuth;
use GlueAgency\Influx\auth\QueryStringAuth;
use GlueAgency\Influx\events\RegisterAuthTypesEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;

/**
 * Registry of auth strategies, keyed by the `type` discriminator each one
 * declares. Built-ins are seeded into the registration event payload before
 * triggering, so listeners can append, override, or remove strategies — see
 * {@see AbstractRegistry} for the shared mechanics.
 *
 *   Event::on(
 *       AuthService::class,
 *       AuthService::EVENT_REGISTER_AUTH_TYPES,
 *       function (RegisterAuthTypesEvent $event) {
 *           $event->authTypes[] = MyHmacAuth::class;
 *       }
 *   );
 *
 * {@see all()} hands out the registry's prototypes: enough to enumerate the
 * types with their `label()` and `schema()` for the CP, while a per-request
 * instance carrying a link's credentials comes from {@see fromConfig()}.
 */
class AuthService extends AbstractRegistry
{
    public const EVENT_REGISTER_AUTH_TYPES = 'registerAuthTypes';

    /**
     * The registered discriminators, for reporting an unresolvable one.
     *
     * @return list<string>
     */
    public function knownTypes(): array
    {
        return array_keys($this->all());
    }

    /**
     * The auth headers + query params for a link's outgoing requests, ready to
     * merge into them. The rule per auth type lives on the strategy classes in
     * {@see \GlueAgency\Influx\auth}, dispatched via {@see fromConfig()}; empty
     * arrays when the link has no auth configured.
     *
     * @return array{headers: array<string,string>, query: array<string,string>}
     * @throws InfluxException when auth is configured but its type no longer
     * resolves (e.g. a third-party strategy was unregistered after the link
     * was saved) — fail loudly rather than fire the request unauthenticated.
     */
    public function requestAuth(Link $link): array
    {
        if (empty($link->auth)) {
            return ['headers' => [], 'query' => []];
        }

        $strategy = $this->fromConfig($link->auth);

        if (! $strategy) {
            throw new InfluxException(
                "Link '{$link->handle}' has an unresolvable auth type '" . ($link->auth['type'] ?? '?') . "'.",
            );
        }

        $auth = $strategy->apply();

        return [
            'headers' => $auth['headers'] ?? [],
            'query'   => $auth['query'] ?? [],
        ];
    }

    /**
     * Build a strategy configured for the link's auth slice, or null when no
     * auth is configured. Returns null for an unknown `type` too — validation
     * on the Link model is what reports that as an error.
     *
     * The registry's prototype only supplies the class; the instance itself is
     * built through the container with the config as its last constructor
     * argument, so a third-party strategy may declare service dependencies
     * ahead of it.
     */
    public function fromConfig(array $config): ?AuthStrategyInterface
    {
        $type = $config['type'] ?? null;

        if (! is_string($type) || $type === '') {
            return null;
        }
        $prototype = $this->item($type);

        if (! $prototype) {
            return null;
        }

        return Craft::createObject(['class' => $prototype::class] + $config);
    }

    /**
     * @return list<class-string<AuthStrategyInterface>>
     */
    protected function defaults(): array
    {
        return [
            BasicAuth::class,
            BearerAuth::class,
            CustomHeaderAuth::class,
            QueryStringAuth::class,
        ];
    }

    protected function itemType(): string
    {
        return AuthStrategyInterface::class;
    }

    protected function keyFor(object $item): string
    {
        return $item::type();
    }

    protected function eventName(): string
    {
        return self::EVENT_REGISTER_AUTH_TYPES;
    }

    protected function eventClass(): string
    {
        return RegisterAuthTypesEvent::class;
    }
}
