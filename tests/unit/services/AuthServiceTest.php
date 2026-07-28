<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use GlueAgency\Influx\auth\BasicAuth;
use GlueAgency\Influx\auth\BearerAuth;
use GlueAgency\Influx\auth\CustomHeaderAuth;
use GlueAgency\Influx\auth\QueryStringAuth;
use GlueAgency\Influx\services\AuthService;

/**
 * What the auth registry adds on top of the shared base: it hands out one
 * config-less PROTOTYPE per registered type — enough for the CP to enumerate
 * `label()` / `schema()` — while {@see AuthService::fromConfig()} builds the
 * per-call instance that actually carries a link's credentials.
 */
class AuthServiceTest extends Unit
{
    public function testRegistersTheBuiltInsAsPrototypes(): void
    {
        $service = new AuthService();

        $all = $service->all();

        $this->assertSame(['basic', 'bearer', 'custom-header', 'querystring'], array_keys($all));
        $this->assertInstanceOf(BasicAuth::class, $all['basic']);
        $this->assertInstanceOf(BearerAuth::class, $all['bearer']);
        $this->assertInstanceOf(CustomHeaderAuth::class, $all['custom-header']);
        $this->assertInstanceOf(QueryStringAuth::class, $all['querystring']);

        $this->assertNull($all['basic']->token, 'A prototype carries no credentials.');
        $this->assertSame($all, $service->all(), 'Prototypes are shared, not rebuilt per call.');
    }

    public function testEnumeratesLabelsAndSchemasOffThePrototypes(): void
    {
        $service = new AuthService();
        $prototype = $service->all()['bearer'];

        $this->assertSame('Bearer token', $prototype::label());
        $this->assertSame(
            ['token'],
            array_column($prototype::schema()->toArray(), 'handle'),
        );
    }

    public function testFromConfigBuildsAConfiguredInstance(): void
    {
        $service = new AuthService();

        $strategy = $service->fromConfig(['type' => 'basic', 'username' => 'alice', 'token' => 'hunter2']);

        $this->assertInstanceOf(BasicAuth::class, $strategy);
        $this->assertSame('alice', $strategy->username);
        $this->assertSame('hunter2', $strategy->token);
        $this->assertNotSame($service->all()['basic'], $strategy, 'The prototype must stay unconfigured.');
        $this->assertNull($service->all()['basic']->token);
    }

    public function testFromConfigRejectsAMissingOrUnknownType(): void
    {
        $service = new AuthService();

        $this->assertNull($service->fromConfig([]));
        $this->assertNull($service->fromConfig(['type' => '']));
        $this->assertNull($service->fromConfig(['type' => 'hmac']));
    }

    public function testKnownTypesDerivesFromTheSameRegistry(): void
    {
        $service = new AuthService();

        $this->assertSame(array_keys($service->all()), $service->knownTypes());
    }
}
