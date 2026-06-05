<?php

namespace TDM\Influx\auth;

use craft\helpers\App;

class BearerAuth extends AbstractAuthStrategy
{
    public static function type(): string
    {
        return 'bearer';
    }

    public static function label(): string
    {
        return 'Bearer token';
    }

    public static function editTemplate(): ?string
    {
        return 'influx/_auth/bearer';
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
