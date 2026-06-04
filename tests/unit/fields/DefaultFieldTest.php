<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\Tests\unit\Support\FakeLink;

/**
 * The fallback strategy. Any Craft field type without a registered handler
 * routes through this — it just reads the feed value at `node` and lets
 * Craft normalise it on `setFieldValue`.
 *
 *  - `node` missing -> fall back to `default`.
 *  - both missing/empty -> null (sync engine treats null as "skip this field").
 */
class DefaultFieldTest extends Unit
{
    public function testReadsValueAtNode(): void
    {
        $strategy = $this->build(
            feed: ['summary' => 'hello'],
            mapping: ['node' => 'summary'],
        );
        $this->assertSame('hello', $strategy->parseField());
    }

    public function testNestedNodeViaDotPath(): void
    {
        $strategy = $this->build(
            feed: ['meta' => ['summary' => 'nested']],
            mapping: ['node' => 'meta.summary'],
        );
        $this->assertSame('nested', $strategy->parseField());
    }

    public function testFallsBackToDefaultWhenNodeMissing(): void
    {
        $strategy = $this->build(
            feed: [],
            mapping: ['node' => 'summary', 'default' => 'fallback'],
        );
        $this->assertSame('fallback', $strategy->parseField());
    }

    public function testFallsBackToDefaultWhenNodeEmpty(): void
    {
        $strategy = $this->build(
            feed: ['summary' => ''],
            mapping: ['node' => 'summary', 'default' => 'fallback'],
        );
        $this->assertSame('fallback', $strategy->parseField());
    }

    public function testReturnsNullWhenNothingResolves(): void
    {
        $strategy = $this->build(
            feed: [],
            mapping: ['node' => 'summary'],
        );
        $this->assertNull($strategy->parseField());
    }

    public function testCraftFieldClassIsNullToActAsFallback(): void
    {
        // DefaultField is only ever returned via FieldsService::$default when
        // no parent class matches — registering it against a Craft FQCN would
        // be wrong.
        $this->assertNull(DefaultField::craftFieldClass());
    }

    private function build(array $feed, array $mapping): DefaultField
    {
        $strategy = new DefaultField();
        $strategy->setContext(
            craftField: null,
            fieldHandle: 'summary',
            fieldInfo: $mapping,
            feedData: $feed,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
        return $strategy;
    }
}
