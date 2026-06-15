<?php

namespace GlueAgency\Influx\auth;

use Craft;
use craft\helpers\App;
use GlueAgency\Influx\helpers\BuilderSchema;

/**
 * HTTP Basic authentication (RFC 7617). The inherited {@see $token} property
 * holds the password — same storage convention as the other strategies, where
 * the one secret always lives under `token` and is env-resolved at apply
 * time. The username supports `$VARNAME` references too.
 */
class BasicAuth extends AbstractAuthStrategy
{
    /**
     * Username half of the credential pair. Stored as written — `$VARNAME`
     * references are resolved at request time.
     */
    public ?string $username = null;

    public static function type(): string
    {
        return 'basic';
    }

    public static function label(): string
    {
        return Craft::t('influx', 'Basic auth');
    }

    public static function editSchema(): array
    {
        return [
            BuilderSchema::text('username', Craft::t('influx', 'Username')),
            BuilderSchema::tokenInput('token', Craft::t('influx', 'Password'), [
                'instructions' => Craft::t('influx', 'Sent as <code>Authorization: Basic &lt;base64(username:password)&gt;</code>.'),
            ]),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['username', 'token'], 'required'],
            [['username', 'token'], 'string'],
        ];
    }

    public function apply(array &$headers, array &$query): void
    {
        $credentials = App::parseEnv($this->username) . ':' . App::parseEnv($this->token);
        $headers['Authorization'] = 'Basic ' . base64_encode($credentials);
    }
}
