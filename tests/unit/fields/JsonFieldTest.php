<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\data\JsonData;
use craft\fields\Json as CraftJsonField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Json;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Json strategy: decode before storing (Craft's
 * programmatic normalize doesn't), and compare documents rather than their
 * serialization.
 */
class JsonFieldTest extends Unit
{
    public function testCraftFieldClassIsJson(): void
    {
        $this->assertSame(CraftJsonField::class, Json::craftFieldClass());
    }

    /**
     * Craft only decodes on the request path, so a raw write of a JSON string
     * stored a document whose whole value WAS that string.
     */
    public function testAJsonStringIsDecodedRatherThanStoredVerbatim(): void
    {
        $parsed = (new Json())->parse($this->context(['payload' => '{"a":1,"b":[2,3]}']));

        $this->assertSame(['a' => 1, 'b' => [2, 3]], $parsed);
    }

    public function testAnAlreadyDecodedValuePassesThrough(): void
    {
        $decoded = ['a' => 1, 'b' => [2, 3]];

        $this->assertSame($decoded, (new Json())->parse($this->context(['payload' => $decoded])));
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new Json())->parse($this->context(['payload' => '{not json']));
    }

    public function testAbsentValueYieldsNull(): void
    {
        $this->assertNull((new Json())->parse($this->context([])));
    }

    public function testKeyOrderIsNotAChange(): void
    {
        $strategy = new Json();
        $incoming = $strategy->parse($this->context(['payload' => ['b' => 2, 'a' => 1]]));
        $stored = new JsonData(['a' => 1, 'b' => 2]);

        $this->assertFalse(
            $strategy->hasChanged($this->context([], current: $stored), $incoming),
            'The same document with its keys in another order is the same document.',
        );
    }

    public function testNestedKeyOrderIsNotAChangeEither(): void
    {
        $strategy = new Json();
        $incoming = $strategy->parse($this->context(['payload' => ['outer' => ['z' => 1, 'a' => 2]]]));
        $stored = new JsonData(['outer' => ['a' => 2, 'z' => 1]]);

        $this->assertFalse($strategy->hasChanged($this->context([], current: $stored), $incoming));
    }

    /**
     * Position is meaning in a JSON array, so unlike object keys, list order is
     * left alone.
     */
    public function testListOrderIsAChange(): void
    {
        $strategy = new Json();
        $incoming = $strategy->parse($this->context(['payload' => ['tags' => ['b', 'a']]]));
        $stored = new JsonData(['tags' => ['a', 'b']]);

        $this->assertTrue($strategy->hasChanged($this->context([], current: $stored), $incoming));
    }

    public function testADifferentValueIsAChange(): void
    {
        $strategy = new Json();
        $incoming = $strategy->parse($this->context(['payload' => ['a' => 2]]));
        $stored = new JsonData(['a' => 1]);

        $this->assertTrue($strategy->hasChanged($this->context([], current: $stored), $incoming));
    }

    private function context(array $feed, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'payload',
            mapping: FieldMapping::fromConfig('payload', ['node' => 'payload']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
