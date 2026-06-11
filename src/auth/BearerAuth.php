<?php

namespace GlueAgency\Influx\auth;

use Craft;
use craft\helpers\App;
use GlueAgency\Influx\helpers\BuilderSchema;

class BearerAuth extends AbstractAuthStrategy
{
    public static function type(): string
    {
        return 'bearer';
    }

    public static function label(): string
    {
        return Craft::t('influx', 'Bearer token');
    }

    public static function editSchema(): array
    {
        return [
            BuilderSchema::code('token', Craft::t('influx', 'Token'), [
                'instructions' => Craft::t('influx', 'Sent as <code>Authorization: Bearer &lt;token&gt;</code>.'),
            ]),
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['token'], 'required'],
            [['token'], 'string'],
        ];
    }

    public function apply(array &$headers, array &$query): void
    {
        $headers['Authorization'] = 'Bearer ' . App::parseEnv($this->token);
    }
}
