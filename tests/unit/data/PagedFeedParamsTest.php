<?php

namespace GlueAgency\Influx\Tests\unit\data;

use Codeception\Test\Unit;
use GlueAgency\Influx\data\PagedFeed;

/**
 * Spec for {@see PagedFeed::withPreservedParams()} — the rule that folds the
 * configured endpoint's own query params into a paginator's next URL when it's
 * missing them.
 *
 * Real-world shape: a site endpoint `…/api/properties?language=fr` whose
 * `links.next` is `…/api/properties?page=2` (no language). Left verbatim,
 * pages 2+ walk the default-language feed and most of the FR feed is
 * unreachable. So the merge adds missing params while the next URL's own
 * params always win (`page=2` survives; an echoed `language` keeps its value).
 *
 * No Craft boot: withPreservedParams() reads its base params from the
 * overridable {@see PagedFeed::baseQueryParams()} seam, so an anonymous
 * subclass supplies them directly and the DataService/endpoint resolution
 * never runs.
 */
class PagedFeedParamsTest extends Unit
{
    public function testAddsAMissingParam(): void
    {
        $url = $this->merge('https://ex.test/api/properties?page=2', ['language' => 'fr']);

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('language=fr', $url);
    }

    public function testExistingParamWins(): void
    {
        // The next URL already carries language=en — the base's fr must NOT
        // clobber it (an API that echoes the param keeps its own value).
        $url = $this->merge('https://ex.test/api/properties?page=2&language=en', ['language' => 'fr']);

        $this->assertStringContainsString('language=en', $url);
        $this->assertStringNotContainsString('language=fr', $url);
    }

    public function testMergesMultipleMissingParams(): void
    {
        $url = $this->merge('https://ex.test/api/x?page=3', ['language' => 'fr', 'apiVersion' => '2']);

        $this->assertStringContainsString('page=3', $url);
        $this->assertStringContainsString('language=fr', $url);
        $this->assertStringContainsString('apiVersion=2', $url);
    }

    public function testNextUrlWithNoQueryGainsAllBaseParams(): void
    {
        $url = $this->merge('https://ex.test/api/properties', ['language' => 'fr']);

        $this->assertStringContainsString('?', $url);
        $this->assertStringContainsString('language=fr', $url);
    }

    public function testNoBaseParamsLeavesUrlUntouched(): void
    {
        $next = 'https://ex.test/api/properties?page=2';

        $this->assertSame($next, $this->merge($next, []));
    }

    public function testFragmentIsPreserved(): void
    {
        $url = $this->merge('https://ex.test/api/x?page=2#frag', ['language' => 'fr']);

        $this->assertStringContainsString('#frag', $url);
        $this->assertStringContainsString('language=fr', $url);
    }

    /**
     * Merge $base into $nextUrl via a PagedFeed whose baseQueryParams() is
     * stubbed to $base — no constructor, no Craft, no endpoint resolution.
     *
     * @param array<string, string> $base
     */
    protected function merge(string $nextUrl, array $base): string
    {
        $subclass = new class($base) extends PagedFeed {
            /** @var array<string, string> */
            protected array $stubbedBase = [];

            /**
             * @param array<string, string> $base
             */
            public function __construct(array $base)
            {
                $this->stubbedBase = $base;
            }

            public function baseQueryParams(): array
            {
                return $this->stubbedBase;
            }

            public function publicMerge(string $nextUrl): string
            {
                return $this->withPreservedParams($nextUrl);
            }
        };

        return $subclass->publicMerge($nextUrl);
    }
}
