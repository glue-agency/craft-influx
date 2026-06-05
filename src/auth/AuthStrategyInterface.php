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
     * Built-ins return English; the controller wraps it in {@see Craft::t}
     * so plugin translation domains can localize their own strategies.
     */
    public static function label(): string;

    /**
     * Path to the Twig partial that renders the strategy-specific form fields
     * on the link edit screen, or null when no extra fields are needed.
     *
     * The link-edit template includes this partial wrapped in
     * `{% namespace 'auth' %}`, so field names/ids inside the partial should
     * be relative (e.g. `name: 'token'`, `id: 'bearer-token'`) — Craft turns
     * them into `auth[token]` / `auth-bearer-token` on render. The partial
     * receives the variables `link`, `readOnly`, and `isActive` (true when
     * this strategy is the link's currently saved type).
     */
    public static function editTemplate(): ?string;

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
