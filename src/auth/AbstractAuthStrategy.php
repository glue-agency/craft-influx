<?php

namespace GlueAgency\Influx\auth;

use craft\base\Model;
use craft\helpers\App;
use GlueAgency\Influx\helpers\SchemaBuilder;

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
        // The `type` discriminator identifies the strategy class, not a property on it
        unset($config['type']);
        parent::__construct($config);
    }

    /**
     * Default empty schema so subclasses that haven't been updated for the
     * SPA's Authentication tab still satisfy the interface contract: an empty
     * builder → no fields → the tab renders nothing for the strategy. The
     * built-ins override this with real SchemaBuilder node lists.
     */
    public static function schema(): SchemaBuilder
    {
        return SchemaBuilder::make();
    }

    /**
     * Resolve `$VARNAME` / alias references in a stored setting at request
     * time, so secrets stay out of Project Config. Deliberately lenient: an
     * unset or empty env var resolves to '' and is sent as an empty credential
     * rather than throwing. Env resolution is environment-specific — a local
     * dev environment legitimately leaves a token blank — so a hard "must be
     * set" check at request time can't tell that apart from a real misconfig
     * and would break dev. The stored value's presence is validated at save
     * time via each strategy's defineRules(); the actual secret is an env
     * concern per deployment.
     */
    protected function resolve(?string $value): string
    {
        return (string) App::parseEnv($value);
    }
}
