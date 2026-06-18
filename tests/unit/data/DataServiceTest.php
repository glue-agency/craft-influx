<?php

namespace GlueAgency\Influx\Tests\unit\data;

use Codeception\Test\Unit;
use GlueAgency\Influx\services\DataService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;

/**
 * Pagination fetch layer: a `next` URL must be requested verbatim, query string
 * and all. Regression test for the pagination-loop false positive — Guzzle's
 * `query` request option, passed even when empty, rebuilt the URI's query from
 * `[]` and so stripped `?page=2` off the next URL. Every page then re-fetched
 * page 1, whose `next` was page 2 again, tripping the "already fetched" guard.
 */
class DataServiceTest extends Unit
{
    public function testFetchUrlPreservesTheUrlQueryString(): void
    {
        $history = [];
        $data = $this->dataServiceRecording($history);

        $data->fetchUrl('https://example.test/api/films?page=2');

        $uri = (string) $history[0]['request']->getUri();
        $this->assertStringContainsString('page=2', $uri);
    }

    /**
     * A DataService whose Guzzle client records every request it makes and
     * always answers with an empty JSON list.
     *
     * @param array<int, array<string, mixed>> $history
     */
    protected function dataServiceRecording(array &$history): DataService
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"items":[]}'),
        ]));
        $stack->push(Middleware::history($history));

        // Skip the constructor (init() builds a real Guzzle client via Craft,
        // which isn't booted in the unit suite) and inject the recording client.
        $data = (new ReflectionClass(DataService::class))->newInstanceWithoutConstructor();

        $property = (new ReflectionClass($data))->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($data, new Client(['handler' => $stack]));

        return $data;
    }
}
