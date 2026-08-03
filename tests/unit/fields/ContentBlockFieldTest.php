<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\ContentBlock as CraftContentBlockField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\ContentBlock;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeElement;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the ContentBlock strategy — one nested element holding one
 * layout's fields, so its sub-mappings write the flat `fields` channel the way a
 * Table's columns do.
 *
 * Two defects the DefaultField fallback had, and both are the point of this
 * class:
 *
 * 1. It wrote NOTHING. Craft's ContentBlock only consumes an array shaped
 *    `['fields' => …]` and ignores anything else without a word, so a flat
 *    handle map saved cleanly and stored nothing at all.
 * 2. It was ALWAYS changed. The stored value is a nested element (compared as
 *    its id) against a JSON blob of the incoming array, so every run re-saved
 *    the owner and cut a revision.
 */
class ContentBlockFieldTest extends Unit
{
    public function testTheValueIsWrappedInTheEnvelopeCraftConsumes(): void
    {
        $parsed = $this->parse(
            ['fields' => ['heading' => ['node' => 'title'], 'body' => ['node' => 'intro']]],
            ['title' => 'Zuurstoftherapie', 'intro' => 'Een inleiding'],
        );

        $this->assertSame(['fields' => ['heading' => 'Zuurstoftherapie', 'body' => 'Een inleiding']], $parsed);
    }

    public function testAHandleTheLayoutNoLongerDeclaresIsSkipped(): void
    {
        // The mapping outlived the field, which is not a structural error — the
        // remaining fields still have a block to write into.
        $parsed = $this->parse(
            ['fields' => ['heading' => ['node' => 'title'], 'gone' => ['node' => 'title']]],
            ['title' => 'Zuurstoftherapie'],
        );

        $this->assertSame(['fields' => ['heading' => 'Zuurstoftherapie']], $parsed);
    }

    public function testEverythingResolvingToNothingStillWritesTheEnvelope(): void
    {
        // The row was addressed, so the feed is authoritative: Craft needs the
        // envelope to clear the fields it names.
        $parsed = $this->parse(
            ['fields' => ['heading' => ['node' => 'title', 'default' => '']]],
            ['title' => ''],
        );

        $this->assertSame(['fields' => ['heading' => null]], $parsed);
    }

    public function testTheRowIsAddressedThroughItsSubMappingsAlone(): void
    {
        $strategy = new ContentBlock();

        $this->assertTrue($strategy->addressed($this->context(
            ['fields' => ['heading' => ['node' => 'title']]],
            ['title' => 'Zuurstoftherapie'],
        )));

        // No node in this item addresses anything, so the field is left alone.
        $this->assertFalse($strategy->addressed($this->context(
            ['fields' => ['heading' => ['node' => 'missing']]],
            ['title' => 'Zuurstoftherapie'],
        )));
    }

    public function testItDeclaresItselfSubfieldsOnly(): void
    {
        // Without this the builder offers the row a source-node picker, for a
        // value that only ever comes from its sub-mappings.
        $this->assertTrue((new ContentBlock())->fieldMeta($this->craftField())['subfieldsOnly']);
    }

    public function testAnUnchangedBlockReadsAsUnchanged(): void
    {
        // The regression: comparing a nested element against an array meant every
        // run re-saved the owner and cut a revision.
        $current = $this->nestedElement(['heading' => 'Zuurstoftherapie']);

        $this->assertFalse($this->differs($current, ['fields' => ['heading' => 'Zuurstoftherapie']]));
        $this->assertTrue($this->differs($current, ['fields' => ['heading' => 'Iets anders']]));
    }

    public function testOnlyTheMappedHandlesAreCompared(): void
    {
        // A field the feed doesn't address isn't this mapping's business; counting
        // it would make an untouched block look changed on its own.
        $current = $this->nestedElement(['heading' => 'Zuurstoftherapie', 'body' => 'Stored body']);

        $this->assertFalse($this->differs($current, ['fields' => ['heading' => 'Zuurstoftherapie']]));
    }

    public function testNothingNestedYetMakesEveryIncomingValueNew(): void
    {
        $this->assertTrue($this->differs(null, ['fields' => ['heading' => 'Zuurstoftherapie']]));
        // …but an incoming clear against no block is not a change.
        $this->assertFalse($this->differs(null, ['fields' => ['heading' => null]]));
    }

    public function testTheCardOffersOneRowPerLayoutField(): void
    {
        $nodes = $this->strategy()->schema($this->craftField())->toArray();

        $this->assertCount(1, $nodes);
        $this->assertSame('subFields', $nodes[0]['type']);
        $this->assertSame('fields', $nodes[0]['handle']);
        $this->assertSame(['heading', 'body'], array_column($nodes[0]['subFields'], 'handle'));
        $this->assertSame(['Heading', 'Body'], array_column($nodes[0]['subFields'], 'label'));
    }

    public function testALayoutWithNoFieldsSaysSoInsteadOfOfferingAnEmptyCard(): void
    {
        $strategy = new class() extends ContentBlock {
            protected function blockLayout(?CraftFieldInterface $field): ?FieldLayout
            {
                return null;
            }
        };

        $nodes = $strategy->schema($this->craftField())->toArray();

        $this->assertSame('note', $nodes[0]['type']);
    }

    /**
     * @param array<string, mixed> $mappingConfig
     * @param array<string, mixed> $item
     */
    protected function parse(array $mappingConfig, array $item): mixed
    {
        return $this->strategy()->parse($this->context($mappingConfig, $item));
    }

    protected function differs(?ElementInterface $current, array $incoming): bool
    {
        $strategy = new class() extends ContentBlock {
            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }

            protected function blockLayout(?CraftFieldInterface $field): ?FieldLayout
            {
                return TestContentBlockLayout::make();
            }
        };

        return $strategy->exposedValueDiffers(
            $this->context(['fields' => ['heading' => ['node' => 'title']]], ['title' => 'x']),
            $current,
            $incoming,
        );
    }

    /**
     * The strategy with the two seams that need a booted Craft stubbed: the
     * block's own layout, and the per-child strategy lookup.
     */
    protected function strategy(): ContentBlock
    {
        return new class() extends ContentBlock {
            protected function blockLayout(?CraftFieldInterface $field): ?FieldLayout
            {
                return TestContentBlockLayout::make();
            }

            protected function fieldEditorFor(CraftFieldInterface $craftField): ?array
            {
                return null;
            }
        };
    }

    /**
     * @param array<string, mixed> $mappingConfig
     * @param array<string, mixed> $item
     */
    protected function context(array $mappingConfig, array $item): FieldContext
    {
        return new FieldContext(
            craftField: $this->craftField(),
            handle: 'summary',
            mapping: FieldMapping::fromConfig('summary', $mappingConfig),
            item: new RemoteItem($item),
            link: FakeLink::make(),
            element: new FakeElement(),
            // Every child here is a plain-text field, so the leaf strategy is
            // the shared fallback — the same one a booted plugin would resolve.
            strategyResolver: static fn(CraftFieldInterface $childField): Field => new class() extends Field {
                public function parse(FieldContext $context): mixed
                {
                    return $context->mapping->resolve($context->item);
                }
            },
        );
    }

    protected function craftField(): CraftFieldInterface
    {
        return $this->createMock(CraftContentBlockField::class);
    }

    /** A nested block holding the given stored values. */
    protected function nestedElement(array $values): ElementInterface
    {
        return new class($values) extends FakeElement {
            /** @var array<string, mixed> */
            public array $stored = [];

            public function __construct(array $values)
            {
                $this->stored = $values;
                parent::__construct();
            }

            public function getSerializedFieldValues(?array $fieldHandles = null): array
            {
                return array_intersect_key($this->stored, array_flip($fieldHandles ?? []));
            }
        };
    }
}

/**
 * The block layout both the schema and the comparison read: two plain-text
 * fields, since what's under test is the envelope and the per-handle compare,
 * not the child strategies.
 */
class TestContentBlockLayout
{
    public static function make(): FieldLayout
    {
        $fields = [];

        foreach (['heading' => 'Heading', 'body' => 'Body'] as $handle => $name) {
            $field = new PlainText();
            $field->handle = $handle;
            $field->name = $name;
            $fields[] = $field;
        }

        // A layout stub rather than a configured one: FieldLayout has no setter
        // for its custom fields, they come from its tabs' elements.
        $layout = new class() extends FieldLayout {
            /** @var list<PlainText> */
            public array $fields = [];

            public function getCustomFields(): array
            {
                return $this->fields;
            }
        };
        $layout->fields = $fields;

        return $layout;
    }
}
