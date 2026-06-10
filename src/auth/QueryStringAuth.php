<?php

namespace TDM\Influx\auth;

use Craft;
use craft\helpers\App;
use TDM\Influx\helpers\BuilderSchema;

class QueryStringAuth extends AbstractAuthStrategy
{
    public ?string $param = null;

    public static function type(): string
    {
        return 'querystring';
    }

    public static function label(): string
    {
        return Craft::t('influx', 'Query string parameter');
    }

    public static function editSchema(): array
    {
        return [
            BuilderSchema::code('param', Craft::t('influx', 'Parameter name'), [
                'instructions' => Craft::t('influx', 'e.g. <code>api_key</code>.'),
            ]),
            BuilderSchema::code('token', Craft::t('influx', 'Token'), [
                'instructions' => Craft::t('influx', 'Appended to every request as the parameter value.'),
            ]),
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
