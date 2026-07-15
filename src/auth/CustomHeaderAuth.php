<?php

namespace GlueAgency\Influx\auth;

use Craft;
use GlueAgency\Influx\helpers\SchemaBuilder;

class CustomHeaderAuth extends AbstractAuthStrategy
{
    public ?string $header = null;

    public static function type(): string
    {
        return 'custom-header';
    }

    public static function label(): string
    {
        return Craft::t('influx', 'Custom header');
    }

    public static function schema(): SchemaBuilder
    {
        return SchemaBuilder::make()
            ->code([
                'handle'       => 'header',
                'label'        => Craft::t('influx', 'Header name'),
                'instructions' => Craft::t('influx', 'e.g. <code>X-API-Key</code>.'),
            ])
            ->tokenInput([
                'handle'       => 'token',
                'label'        => Craft::t('influx', 'Token'),
                'instructions' => Craft::t('influx', 'Used as the header value.'),
            ]);
    }

    protected function defineRules(): array
    {
        return [
            [['token', 'header'], 'required'],
            [['token', 'header'], 'string'],
        ];
    }

    public function apply(): array
    {
        return ['headers' => [$this->header => $this->resolve($this->token)]];
    }
}
