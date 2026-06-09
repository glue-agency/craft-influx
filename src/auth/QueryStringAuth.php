<?php

namespace TDM\Influx\auth;

use Craft;
use craft\helpers\App;

class QueryStringAuth extends AbstractAuthStrategy
{
    public ?string $param = null;

    public static function type(): string
    {
        return 'querystring';
    }

    public static function label(): string
    {
        return 'Query string parameter';
    }

    public static function editSchema(): array
    {
        return [
            [
                'handle'       => 'param',
                'label'        => Craft::t('influx', 'Parameter name'),
                'instructions' => Craft::t('influx', 'e.g. <code>api_key</code>.'),
                'inputType'    => 'code',
            ],
            [
                'handle'       => 'token',
                'label'        => Craft::t('influx', 'Token'),
                'instructions' => Craft::t('influx', 'Appended to every request as the parameter value.'),
                'inputType'    => 'code',
            ],
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['token', 'param'], 'required'],
            [['token', 'param'], 'string'],
        ];
    }

    public function apply(array &$headers, array &$query): void
    {
        $query[$this->param] = App::parseEnv($this->token);
    }
}
