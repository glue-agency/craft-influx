<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Closure;
use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the two collaborators a {@see FieldContext} carries so the
 * mapping walk never reaches the plugin singleton mid-flight: strategy
 * resolution ({@see FieldContext::strategyFor()}) and the sub-mapping walk
 * ({@see FieldContext::applySubMappings()}).
 *
 * Both must survive {@see FieldContext::descend()} — a sub-mapping is part of
 * the same walk, so it has to resolve child strategies and nested sub-mappings
 * through the same seams the top level got.
 */
class FieldContextSeamsTest extends Unit
{
    public function testStrategyResolutionGoesThroughTheInjectedResolver(): void
    {
        $marker = $this->markerStrategy();
        $asked = [];

        $context = $this->context(
            static function(CraftFieldInterface $field) use ($marker, &$asked): Field {
                $asked[] = $field;

                return $marker;
            },
        );

        $craftField = $this->createMock(CraftFieldInterface::class);

        $this->assertSame($marker, $context->strategyFor($craftField));
        $this->assertSame([$craftField], $asked);
    }

    public function testDescendCarriesBothSeamsToTheChildContext(): void
    {
        $marker = $this->markerStrategy();
        $applier = $this->spyApplier();
        $resolver = static fn(CraftFieldInterface $field): Field => $marker;

        $context = $this->context($resolver, $applier);
        $child = $context->descend(
            $this->createMock(ElementInterface::class),
            FieldMapping::fromConfig('sub', ['node' => 'sub']),
        );

        $this->assertSame($resolver, $child->strategyResolver);
        $this->assertSame($applier, $child->applier);
        $this->assertSame($marker, $child->strategyFor($this->createMock(CraftFieldInterface::class)));
    }

    public function testSubMappingsRunThroughTheLentApplier(): void
    {
        $applier = $this->spyApplier();
        $context = $this->context(null, $applier);
        $subElement = $this->createMock(ElementInterface::class);

        $this->assertTrue($context->applySubMappings($subElement));
        $this->assertSame([[$context, $subElement]], $applier->calls);
    }

    /**
     * A strategy instance that only has to be recognisable by identity.
     */
    protected function markerStrategy(): Field
    {
        return new class() extends Field {
            public function parse(FieldContext $context): mixed
            {
                return null;
            }
        };
    }

    /**
     * An applier that records the context + element each sub-mapping walk was
     * asked for, and always reports a change.
     */
    protected function spyApplier(): MappingApplier
    {
        return new class() extends MappingApplier {
            /** @var list<array{0: FieldContext, 1: ElementInterface}> */
            public array $calls = [];

            public function applySubMappings(FieldContext $parentContext, ElementInterface $element): bool
            {
                $this->calls[] = [$parentContext, $element];

                return true;
            }
        };
    }

    protected function context(?Closure $resolver = null, ?MappingApplier $applier = null): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', ['node' => 'ref']),
            item: new RemoteItem(['ref' => 'abc']),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            strategyResolver: $resolver,
            applier: $applier,
        );
    }
}
