<?php

namespace TDM\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use TDM\Influx\models\Link;
use TDM\Influx\Tests\unit\Support\FakeLink;

/**
 * Contract test for the LinkBuilder wire shape. PHP is the authority
 * ({@see Link::toBuilderArray()}); the committed fixture is the contract
 * artifact, and the SPA asserts its own assumptions against the same file
 * (see `src/web/assets/links/src/builder/__tests__/contract.test.js`).
 *
 * If this test fails after a deliberate shape change: update the fixture,
 * `builder/types.js`, and the JS contract test together.
 */
class LinkBuilderPayloadTest extends Unit
{
    public function testToBuilderArrayMatchesTheCommittedFixture(): void
    {
        $link = FakeLink::make([
            'mappings' => ['importId' => ['node' => 'id']],
        ]);

        $this->assertEquals(
            $this->fixture(),
            $this->normalize($link->toBuilderArray()),
            'Link::toBuilderArray() drifted from the committed wire-contract fixture.',
        );
    }

    public function testApplyBuilderPayloadRoundTripsTheFixture(): void
    {
        $link = new Link();
        $link->applyBuilderPayload($this->fixture());

        $this->assertEquals(
            $this->fixture(),
            $this->normalize($link->toBuilderArray()),
            'Applying the fixture payload and re-serializing must be lossless.',
        );
    }

    /**
     * Round-trip through JSON so the (object) casts compare the way the
     * SPA sees them.
     */
    protected function normalize(array $payload): array
    {
        return json_decode(json_encode($payload), true);
    }

    protected function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/links/tests/fixtures/link-payload.json';
        return json_decode(file_get_contents($path), true);
    }
}
