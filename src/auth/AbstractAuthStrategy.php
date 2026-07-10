<?php

namespace GlueAgency\Influx\auth;

use craft\base\Model;
use craft\helpers\App;

/**
 * Base for auth strategies. Strategies are real Craft/Yii models so per-type
 * validation can live in `defineRules()` and reuse the framework's standard
 * validators instead of a hand-rolled closure-based protocol.
 *
 * The {@see \GlueAgency\Influx\services\AuthService} builds a strategy via
 * `new $class($config)`, where `$config` is the link's `auth` slice — the
 * `type` key is stripped here so it doesn't get assigned as a property.
 */
abstract class AbstractAuthStrategy extends Model implements AuthStrategyInterface
{
    /**
     * Token / secret. Stored as written by the user — `$VARNAME` references
     * are resolved at request time, not at save time, so secrets stay out of
     * Project Config.
     */
    public ?string $token = null;

    public function __construct(array $config = [])
    {
        // The `type` discriminator lives in the auth slice but identifies the
        // strategy class — it isn't a property on the strategy itself.
        unset($config['type']);
        parent::__construct($config);
    }

    /**
     * Default empty schema so subclasses that haven't been updated for the
     * SPA's Authentication tab still satisfy the interface contract: no
     * fields → no schema → the tab renders nothing for the strategy. The
     * three built-ins override this with real SchemaBuilder node lists.
     */
    public static function schema(): array
    {
        return [];
    }

    /**
     * Resolve `$VARNAME` / alias references in a stored setting at request
     * time, so secrets stay out of Project Config. Null-safe: an unset
     * setting resolves to '' (unreachable for required settings — every
     * built-in strategy `require`s the values it applies).
     */
    protected function resolve(?string $value): string
    {
        return (string) App::parseEnv($value);
    }
}
