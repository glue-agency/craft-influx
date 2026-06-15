<?php

namespace GlueAgency\Influx\Tests\unit\auth;

use Codeception\Test\Unit;
use GlueAgency\Influx\auth\BasicAuth;
use GlueAgency\Influx\services\AuthService;

/**
 * Spec for the Basic auth strategy: the inherited `token` property carries
 * the password, both halves resolve `$VARNAME` references at apply time, and
 * the result is a single RFC 7617 `Authorization: Basic` header — the query
 * string is left untouched.
 */
class BasicAuthTest extends Unit
{
    public function testAppliesBase64CredentialPair(): void
    {
        $strategy = new BasicAuth(['username' => 'alice', 'token' => 'hunter2']);

        $headers = [];
        $query = [];
        $strategy->apply($headers, $query);

        $this->assertSame(['Authorization' => 'Basic ' . base64_encode('alice:hunter2')], $headers);
        $this->assertSame([], $query);
    }

    public function testResolvesEnvReferencesAtApplyTime(): void
    {
        $_SERVER['INFLUX_TEST_BASIC_USER'] = 'bob';
        $_SERVER['INFLUX_TEST_BASIC_PASS'] = 's3cret';

        try {
            $strategy = new BasicAuth([
                'username' => '$INFLUX_TEST_BASIC_USER',
                'token'    => '$INFLUX_TEST_BASIC_PASS',
            ]);

            $headers = [];
            $query = [];
            $strategy->apply($headers, $query);

            $this->assertSame('Basic ' . base64_encode('bob:s3cret'), $headers['Authorization']);
        } finally {
            unset($_SERVER['INFLUX_TEST_BASIC_USER'], $_SERVER['INFLUX_TEST_BASIC_PASS']);
        }
    }

    public function testRequiresUsernameAndPassword(): void
    {
        $strategy = new BasicAuth(['username' => 'alice', 'token' => 'hunter2']);
        $this->assertTrue($strategy->validate());

        $strategy = new BasicAuth(['token' => 'hunter2']);
        $this->assertFalse($strategy->validate());
        $this->assertArrayHasKey('username', $strategy->getErrors());

        $strategy = new BasicAuth(['username' => 'alice']);
        $this->assertFalse($strategy->validate());
        $this->assertArrayHasKey('token', $strategy->getErrors());
    }

    public function testRegisteredAsBuiltIn(): void
    {
        $service = new AuthService();

        $strategy = $service->fromConfig(['type' => 'basic', 'username' => 'alice', 'token' => 'hunter2']);

        $this->assertInstanceOf(BasicAuth::class, $strategy);
        $this->assertContains('basic', $service->knownTypes());
    }
}
