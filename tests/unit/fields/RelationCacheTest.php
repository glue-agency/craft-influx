<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\fields\Relation;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\ElementLookupCache;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Behaviour spec for the relational lookup cache path in {@see Relation::parse()}:
 * a pre-populated cache serves the element without touching findOne(), and a
 * null cache falls straight through to findOne() (back-compat for directly
 * built contexts).
 */
class RelationCacheTest extends Unit
{
    public function testParseUsesCachedElementWithoutInvokingFindOne(): void
    {
        $related = $this->createConfiguredMock(ElementInterface::class, ['getId' => 7]);
        $related->id = 7;

        $cache = new ElementLookupCache();
        $strategy = $this->throwingStrategy();

        // Pre-seed the cache exactly as the run would after a first lookup,
        // under the scope parse() computes (no craftField → empty scope).
        $cache->put($strategy->exposedElementType(), 'id', '', 'abc', $related);

        $context = $this->context($cache);

        $this->assertSame([7], $strategy->parse($context));
        $this->assertSame(0, $strategy->findOneCalls);
    }

    public function testParseFallsThroughToFindOneWhenNoCachePresent(): void
    {
        $related = $this->createMock(ElementInterface::class);
        $related->id = 9;

        $strategy = $this->countingStrategy($related);
        $context = $this->context(null);

        $this->assertSame([9], $strategy->parse($context));
        $this->assertSame(1, $strategy->findOneCalls);
    }

    /**
     * Anonymous Relation whose findOne() throws — proving a cached hit never
     * reaches it.
     */
    protected function throwingStrategy(): Relation
    {
        return new class() extends Relation {
            public int $findOneCalls = 0;

            public function exposedElementType(): string
            {
                return $this->elementType();
            }

            protected function elementType(): string
            {
                return \craft\elements\Entry::class;
            }

            protected function findOne(FieldContext $context, string $match, mixed $value): ?ElementInterface
            {
                $this->findOneCalls++;

                throw new RuntimeException('findOne must not run for a cached hit');
            }
        };
    }

    /**
     * Anonymous Relation whose findOne() counts invocations and returns a fixed
     * element — proving the null-cache path still resolves.
     */
    protected function countingStrategy(ElementInterface $element): Relation
    {
        $strategy = new class() extends Relation {
            public int $findOneCalls = 0;

            public ?ElementInterface $stub = null;

            protected function elementType(): string
            {
                return \craft\elements\Entry::class;
            }

            protected function findOne(FieldContext $context, string $match, mixed $value): ?ElementInterface
            {
                $this->findOneCalls++;

                return $this->stub;
            }
        };
        $strategy->stub = $element;

        return $strategy;
    }

    protected function context(?ElementLookupCache $cache): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);

        return new FieldContext(
            craftField: null,
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', ['node' => 'ref']),
            item: new RemoteItem(['ref' => 'abc']),
            link: FakeLink::make(),
            element: $element,
            lookups: $cache,
        );
    }
}
