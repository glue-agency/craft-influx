<?php

namespace TDM\Influx\auth;

/**
 * Per-auth-type strategy. One concrete class per shape currently stored under
 * `Link::$auth` — bearer / custom header / query-string. Adding a new auth
 * type means writing a new strategy and registering it through
 * {@see \TDM\Influx\services\AuthService}; no other code needs to learn the
 * difference.
 *
 * Strategies are stateful value objects: they're constructed with the
 * config slice from a Link and used once per HTTP call.
 */
interface AuthStrategyInterface
{
    /**
     * Key under which {@see Link::$auth['type']} stores this strategy.
     */
    public static function type(): string;

    /**
     * Human-readable label shown in the CP "Authentication type" dropdown.
     * Return it through `Craft::t()` so your plugin's translation category
     * applies — nothing downstream re-translates it.
     */
    public static function label(): string;

    /**
     * Form schema rendered by the LinkBuilder SPA's Authentication tab when
     * this strategy is selected: a list of
     * {@see \TDM\Influx\helpers\BuilderSchema} nodes — the same vocabulary
     * the mapping extras use, rendered by the same generic SchemaForm.
     * Each node's `handle` keys into the link's `auth` slice. Return an
     * empty array when the strategy needs no extra fields (e.g. a
     * hypothetical "anonymous" strategy).
     *
     * Labels / instructions are not auto-translated; return them through
     * `Craft::t()` yourself so your plugin's translation category applies.
     *
     * @return list<array>
     */
    public static function editSchema(): array;

    /**
     * Mutate the outgoing request's headers + query string to attach
     * credentials. Implementations resolve env-variable references at call
     * time so secrets stay out of Project Config.
     *
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    public function apply(array &$headers, array &$query): void;
}
