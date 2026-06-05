<?php

namespace TDM\Influx\auth;

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

    public static function editTemplate(): ?string
    {
        return 'influx/_auth/querystring';
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
