<?php

namespace TDM\Influx\auth;

use Craft;
use craft\helpers\App;

class CustomHeaderAuth extends AbstractAuthStrategy
{
    public ?string $header = null;

    public static function type(): string
    {
        return 'custom-header';
    }

    public static function label(): string
    {
        return 'Custom header';
    }

    public static function editSchema(): array
    {
        return [
            [
                'handle'       => 'header',
                'label'        => Craft::t('influx', 'Header name'),
                'instructions' => Craft::t('influx', 'e.g. <code>X-API-Key</code>.'),
                'inputType'    => 'code',
            ],
            [
                'handle'       => 'token',
                'label'        => Craft::t('influx', 'Token'),
                'instructions' => Craft::t('influx', 'Used verbatim as the header value.'),
                'inputType'    => 'code',
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['token', 'header'], 'required'],
            [['token', 'header'], 'string'],
        ];
    }

    public function apply(array &$headers, array &$query): void
    {
        $headers[$this->header] = App::parseEnv($this->token);
    }
}
