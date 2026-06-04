<?php

namespace TDM\Influx\auth;

/**
 * Per-auth-type strategy. One concrete class per shape currently stored under
 * `Link::$auth` — bearer / custom header / query-string. Adding a new auth
 * type means writing a new strategy and teaching {@see AuthFactory} about it;
 * no other code needs to learn the difference.
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
     * Validate the link's auth config slice. Add an error to the link via
     * the closure when the shape is wrong (so the model's own validate()
     * lifecycle keeps owning error reporting).
     *
     * @param callable(string $message): void $addError
     */
    public function validate(callable $addError): void;

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
