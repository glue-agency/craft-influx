<?php

namespace TDM\Influx\auth;

class CustomHeaderAuth extends AbstractAuthStrategy
{
    public static function type(): string
    {
        return 'custom';
    }

    public function validate(callable $addError): void
    {
        if (empty($this->config['token'])) {
            $addError('Custom auth requires a token.');
        }
        if (empty($this->config['header'])) {
            $addError('Custom auth requires a header name.');
        }
    }

    public function apply(array &$headers, array &$query): void
    {
        $token = $this->resolvedToken();
        if ($token === '') {
            return;
        }
        $name = trim((string)($this->config['header'] ?? ''));
        if ($name === '') {
            return;
        }
        $headers[$name] = $token;
    }
}
