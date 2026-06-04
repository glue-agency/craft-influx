<?php

namespace TDM\Influx\auth;

class QueryStringAuth extends AbstractAuthStrategy
{
    public static function type(): string
    {
        return 'querystring';
    }

    public function validate(callable $addError): void
    {
        if (empty($this->config['token'])) {
            $addError('Querystring auth requires a token.');
        }
        if (empty($this->config['param'])) {
            $addError('Querystring auth requires a parameter name.');
        }
    }

    public function apply(array &$headers, array &$query): void
    {
        $token = $this->resolvedToken();
        if ($token === '') {
            return;
        }
        $param = trim((string)($this->config['param'] ?? ''));
        if ($param === '') {
            return;
        }
        $query[$param] = $token;
    }
}
