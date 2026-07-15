<?php

namespace GlueAgency\Influx\auth;

use Craft;
use GlueAgency\Influx\helpers\SchemaBuilder;

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

    public static function schema(): SchemaBuilder
    {
        return SchemaBuilder::make()
            ->text(['handle' => 'username', 'label' => Craft::t('influx', 'Username')])
            ->tokenInput([
                'handle'       => 'token',
                'label'        => Craft::t('influx', 'Password'),
                'instructions' => Craft::t('influx', 'Sent as <code>Authorization: Basic &lt;base64(username:password)&gt;</code>.'),
            ]);
    }

    protected function defineRules(): array
    {
        return [
            [['username', 'token'], 'required'],
            [['username', 'token'], 'string'],
        ];
    }

    public function apply(): array
    {
        $credentials = $this->resolve($this->username) . ':' . $this->resolve($this->token);

        return ['headers' => ['Authorization' => 'Basic ' . base64_encode($credentials)]];
    }
}
