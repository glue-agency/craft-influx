<?php

namespace GlueAgency\Influx\auth;

use Craft;
use GlueAgency\Influx\helpers\SchemaBuilder;

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

    public static function schema(): array
    {
        return SchemaBuilder::make()
            ->tokenInput([
                'handle'       => 'token',
                'label'        => Craft::t('influx', 'Token'),
                'instructions' => Craft::t('influx', 'Sent as <code>Authorization: Bearer &lt;token&gt;</code>.'),
            ])
            ->toArray();
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
        $headers['Authorization'] = 'Bearer ' . $this->resolve($this->token);
    }
}
