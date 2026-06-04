<?php

namespace TDM\Influx\auth;

/**
 * Resolves a Link's raw auth config slice into the right strategy.
 *
 * Adding a new auth type means writing the strategy class and registering it
 * here (or via {@see register()} from a plugin) — no switch statements
 * elsewhere in the codebase.
 */
class AuthFactory
{
    /** @var array<string, class-string<AuthStrategyInterface>> */
    private static array $strategies = [];

    /**
     * @param class-string<AuthStrategyInterface> $class
     */
    public static function register(string $class): void
    {
        self::$strategies[$class::type()] = $class;
    }

    /**
     * @return list<string>
     */
    public static function knownTypes(): array
    {
        self::ensureDefaults();
        return array_keys(self::$strategies);
    }

    /**
     * Build a strategy for the link's auth config, or null when no auth is
     * configured. Returns null for an unknown `type` too — validation on
     * the Link model is what reports that as an error.
     */
    public static function fromConfig(array $config): ?AuthStrategyInterface
    {
        self::ensureDefaults();

        $type = $config['type'] ?? null;
        if (!is_string($type) || $type === '') {
            return null;
        }
        $class = self::$strategies[$type] ?? null;
        if (!$class) {
            return null;
        }
        return new $class($config);
    }

    private static function ensureDefaults(): void
    {
        if (!empty(self::$strategies)) {
            return;
        }
        self::register(BearerAuth::class);
        self::register(CustomHeaderAuth::class);
        self::register(QueryStringAuth::class);
    }
}
