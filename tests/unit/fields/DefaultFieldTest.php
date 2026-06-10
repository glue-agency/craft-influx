<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\models\FieldMapping;
use TDM\Influx\sync\FieldContext;
use TDM\Influx\sync\RemoteItem;
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
        $context = $this->context(
            feed: ['summary' => 'hello'],
            mapping: ['node' => 'summary'],
        );
        $this->assertSame('hello', (new DefaultField())->parse($context));
    }

    public function testNestedNodeViaDotPath(): void
    {
        $context = $this->context(
            feed: ['meta' => ['summary' => 'nested']],
            mapping: ['node' => 'meta.summary'],
        );
        $this->assertSame('nested', (new DefaultField())->parse($context));
    }

    public function testFallsBackToDefaultWhenNodeMissing(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'summary', 'default' => 'fallback'],
        );
        $this->assertSame('fallback', (new DefaultField())->parse($context));
    }

    public function testFallsBackToDefaultWhenNodeEmpty(): void
    {
        $context = $this->context(
            feed: ['summary' => ''],
            mapping: ['node' => 'summary', 'default' => 'fallback'],
        );
        $this->assertSame('fallback', (new DefaultField())->parse($context));
    }

    public function testReturnsNullWhenNothingResolves(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'summary'],
        );
        $this->assertNull((new DefaultField())->parse($context));
    }

    public function testDefaultAloneIsIgnoredWithoutOptIn(): void
    {
        // A typed-but-unactivated default must not write anything — the
        // user has to pick "— use default —" explicitly.
        $context = $this->context(
            feed: ['summary' => 'hello'],
            mapping: ['default' => 'fallback'],
        );
        $this->assertNull((new DefaultField())->parse($context));
    }

    public function testUseDefaultAppliesTheDefaultWithoutANode(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['default' => 'fallback', 'useDefault' => true],
        );
        $this->assertSame('fallback', (new DefaultField())->parse($context));
    }

    public function testCraftFieldClassIsNullToActAsFallback(): void
    {
        // DefaultField is only ever returned via FieldsService::$default when
        // no parent class matches — registering it against a Craft FQCN would
        // be wrong.
        $this->assertNull(DefaultField::craftFieldClass());
    }

    private function context(array $feed, array $mapping): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'summary',
            mapping: FieldMapping::fromConfig('summary', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
