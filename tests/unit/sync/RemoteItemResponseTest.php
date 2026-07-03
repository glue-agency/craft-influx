<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * Behaviour spec for {@see RemoteItem::fromItemResponse()} — building an item
 * from a SINGLE-resource response (a link's itemEndpoint). APIs that envelope
 * their list feed (`{"data": [...]}`, declared via the link's rootNode) wrap
 * the single resource the same way (`{"data": {...}}`); the factory unwraps
 * that envelope so match paths resolve against the resource, not the wrapper.
 */
class RemoteItemResponseTest extends Unit
{
    public function testNoRootNodeUsesResponseAsIs(): void
    {
        $item = RemoteItem::fromItemResponse(['id' => 7, 'name' => 'A'], null);

        $this->assertSame(7, $item->get('id'));
    }

    public function testUnwrapsObjectUnderRootNode(): void
    {
        $item = RemoteItem::fromItemResponse(
            ['data' => ['id' => 7, 'name' => 'A'], 'meta' => ['total' => 1]],
            'data',
        );

        $this->assertSame(['id' => 7, 'name' => 'A'], $item->raw());
        $this->assertSame(7, $item->get('id'));
    }

    public function testUnwrapsNestedRootNodePath(): void
    {
        $item = RemoteItem::fromItemResponse(
            ['result' => ['data' => ['id' => 7]]],
            'result.data',
        );

        $this->assertSame(7, $item->get('id'));
    }

    public function testUnwrapsFirstElementOfAListRootNode(): void
    {
        // Some APIs answer a single-resource fetch with a one-item collection.
        $item = RemoteItem::fromItemResponse(
            ['data' => [['id' => 7], ['id' => 8]]],
            'data',
        );

        $this->assertSame(7, $item->get('id'));
    }

    public function testBareObjectResponseSurvivesAConfiguredRootNode(): void
    {
        // The list feed is enveloped but the item endpoint returns the bare
        // resource — a missing rootNode falls back to the whole response.
        $item = RemoteItem::fromItemResponse(['id' => 7, 'name' => 'A'], 'data');

        $this->assertSame(7, $item->get('id'));
    }

    public function testScalarUnderRootNodeFallsBackToResponse(): void
    {
        $item = RemoteItem::fromItemResponse(['data' => 'nope', 'id' => 7], 'data');

        $this->assertSame(7, $item->get('id'));
    }

    public function testScalarListUnderRootNodeFallsBackToResponse(): void
    {
        $item = RemoteItem::fromItemResponse(['data' => [1, 2, 3], 'id' => 7], 'data');

        $this->assertSame(7, $item->get('id'));
    }
}
