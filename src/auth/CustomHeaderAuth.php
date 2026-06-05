<?php

namespace TDM\Influx\auth;

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

    public static function editTemplate(): ?string
    {
        return 'influx/_auth/custom-header';
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
