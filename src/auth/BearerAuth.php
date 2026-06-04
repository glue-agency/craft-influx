<?php

namespace TDM\Influx\auth;

class BearerAuth extends AbstractAuthStrategy
{
    public static function type(): string
    {
        return 'bearer';
    }

    public function validate(callable $addError): void
    {
        if (empty($this->config['token'])) {
            $addError('Bearer auth requires a token.');
        }
    }

    public function apply(array &$headers, array &$query): void
    {
        $token = $this->resolvedToken();
        if ($token === '') {
            return;
        }
        $headers['Authorization'] = 'Bearer ' . $token;
    }
}
