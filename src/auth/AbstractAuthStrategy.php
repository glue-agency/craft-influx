<?php

namespace TDM\Influx\auth;

use craft\helpers\App;

abstract class AbstractAuthStrategy implements AuthStrategyInterface
{
    /** Raw `Link::$auth` config slice (already validated). */
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Pull the token out of the config and resolve $ENV references. Returns
     * an empty string when the token is unset, blank, or points at an env
     * var that isn't defined — callers short-circuit on the empty result.
     */
    protected function resolvedToken(): string
    {
        $raw = (string)($this->config['token'] ?? '');
        if ($raw === '') {
            return '';
        }
        $resolved = App::parseEnv($raw);
        return is_string($resolved) ? $resolved : '';
    }
}
