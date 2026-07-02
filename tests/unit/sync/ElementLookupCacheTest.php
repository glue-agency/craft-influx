<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\sync\ElementLookupCache;

/**
 * Behaviour spec for {@see ElementLookupCache}: the resolver runs once per key
 * (hits AND misses cached), put() can flip a cached miss to a hit, keys are
 * scoped by type/match/scope/value, and the map is bounded by MAX_ENTRIES.
 */
class ElementLookupCacheTest extends Unit
{
    public function testResolverRunsOncePerKeyThenServesFromCache(): void
    {
        $cache = new ElementLookupCache();
        $element = $this->createMock(ElementInterface::class);
        $calls = 0;

        $resolve = function() use (&$calls, $element): ElementInterface {
            $calls++;

            return $element;
        };

        $first = $cache->remember('Entry', 'id', 'field-1', '42', $resolve);
        $second = $cache->remember('Entry', 'id', 'field-1', '42', $resolve);

        $this->assertSame($element, $first);
        $this->assertSame($element, $second);
        $this->assertSame(1, $calls);
    }

    public function testMissIsCachedAndResolverNotReInvoked(): void
    {
        $cache = new ElementLookupCache();
        $calls = 0;

        $resolve = function() use (&$calls): ?ElementInterface {
            $calls++;

            return null;
        };

        $this->assertNull($cache->remember('User', 'email', 'author', 'ghost@example.com', $resolve));
        $this->assertNull($cache->remember('User', 'email', 'author', 'ghost@example.com', $resolve));
        $this->assertSame(1, $calls);
    }

    public function testPutFlipsACachedMissToAHit(): void
    {
        $cache = new ElementLookupCache();
        $element = $this->createMock(ElementInterface::class);

        // Confirmed miss first.
        $this->assertNull($cache->remember('Entry', 'slug', 'field-1', 'news', fn(): ?ElementInterface => null));

        // Creation flips it to a hit.
        $cache->put('Entry', 'slug', 'field-1', 'news', $element);

        $throwing = function(): ?ElementInterface {
            throw new \RuntimeException('resolver must not run for a cached hit');
        };

        $this->assertSame($element, $cache->remember('Entry', 'slug', 'field-1', 'news', $throwing));
    }

    public function testKeysDifferIndependentlyByTypeMatchScopeAndValue(): void
    {
        $cache = new ElementLookupCache();
        $a = $this->createMock(ElementInterface::class);
        $b = $this->createMock(ElementInterface::class);
        $c = $this->createMock(ElementInterface::class);
        $d = $this->createMock(ElementInterface::class);

        $cache->put('Entry', 'id', 'field-1', '1', $a);
        $cache->put('User', 'id', 'field-1', '1', $b);
        $cache->put('Entry', 'slug', 'field-1', '1', $c);
        $cache->put('Entry', 'id', 'field-2', '1', $d);

        $throwing = function(): ?ElementInterface {
            throw new \RuntimeException('should be cached');
        };

        $this->assertSame($a, $cache->remember('Entry', 'id', 'field-1', '1', $throwing));
        $this->assertSame($b, $cache->remember('User', 'id', 'field-1', '1', $throwing));
        $this->assertSame($c, $cache->remember('Entry', 'slug', 'field-1', '1', $throwing));
        $this->assertSame($d, $cache->remember('Entry', 'id', 'field-2', '1', $throwing));

        // A different value on an otherwise-identical key is a cold key.
        $calls = 0;
        $cache->remember('Entry', 'id', 'field-1', '2', function() use (&$calls): ?ElementInterface {
            $calls++;

            return null;
        });
        $this->assertSame(1, $calls);
    }

    public function testOldestEntryEvictedPastMaxEntries(): void
    {
        $cache = new ElementLookupCache();

        // Seed MAX_ENTRIES + 1 distinct values; the first (oldest) is evicted.
        for ($i = 0; $i <= 500; $i++) {
            $cache->remember('Entry', 'id', 'field-1', (string) $i, fn(): ?ElementInterface => null);
        }

        // The evicted key resolves cold again (resolver runs).
        $calls = 0;
        $cache->remember('Entry', 'id', 'field-1', '0', function() use (&$calls): ?ElementInterface {
            $calls++;

            return null;
        });
        $this->assertSame(1, $calls);

        // A key added after the eviction ceiling is still cached.
        $throwing = function(): ?ElementInterface {
            throw new \RuntimeException('recent key should be cached');
        };
        $this->assertNull($cache->remember('Entry', 'id', 'field-1', '500', $throwing));
    }
}
