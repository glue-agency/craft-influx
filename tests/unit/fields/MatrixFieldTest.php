<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Cake\Utility\Hash;
use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\models\FieldLayout;
use GlueAgency\Influx\exceptions\MappingDepthException;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Matrix mapping strategy — the no-boot half. Compat's
 * block-type discovery and throwaway-element construction, plus the child
 * FieldsService lookup, are stubbed via a testable subclass so the index-zip,
 * child-coercion, addressed gate, error path, and change-detection logic can
 * be exercised without a running Craft.
 *
 * Mappings live under the Feed Me-shaped `blocks` channel: one node-less
 * sub-mapping tree per block-type handle ({@see FieldMapping::blockMappings()}),
 * whose child node paths are absolute (resolved against the top-level item) and
 * index-zipped into blocks. Blocks are grouped by type in the field's declared
 * order, with a continuous `newN` counter across types.
 */
class MatrixFieldTest extends Unit
{
    public function testActiveChildrenIndexZipIntoBlocksInOrder(): void
    {
        $item = new RemoteItem([
            'seasons' => [
                ['year' => 2020, 'summary' => 'a'],
                ['year' => 2021, 'summary' => 'b'],
                ['year' => 2022, 'summary' => 'c'],
            ],
        ]);

        $strategy = $this->strategy(['season' => ['year', 'summary']]);
        $blocks = $strategy->parse($this->context($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'seasons.year'],
                    'notes' => ['node' => 'seasons.summary'],
                ],
            ],
        ]));

        $this->assertSame(['new1', 'new2', 'new3'], array_keys($blocks));

        foreach ([2020, 2021, 2022] as $i => $year) {
            $key = 'new' . ($i + 1);
            $this->assertSame('season', $blocks[$key]['type']);
            $this->assertTrue($blocks[$key]['enabled']);
            // The fake child strategy echoes the resolved value with a marker,
            // proving coercion ran through the child strategy for that block.
            $this->assertSame('coerced:' . $year, $blocks[$key]['fields']['year']);
        }

        // The child received a synthetic single-value item that resolves to
        // exactly this block's value.
        $recorded = $strategy->recordedContexts['year'][1];
        $this->assertSame(2021, $recorded->mapping->resolve($recorded->item));
    }

    public function testScalarChildrenCollapseToSingleBlock(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020, 'summary' => 'only']]]);

        $blocks = $this->strategy(['season' => ['year', 'summary']])->parse($this->context($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'seasons.year'],
                    'notes' => ['node' => 'seasons.summary'],
                ],
            ],
        ]));

        $this->assertCount(1, $blocks);
        $this->assertSame(['new1'], array_keys($blocks));
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
    }

    public function testInactiveAndUnmappedChildrenNeverContribute(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);

        $blocks = $this->strategy(['season' => ['year']])->parse($this->context($item, [
            'season' => [
                'fields' => [
                    'year' => ['node' => 'seasons.year'],
                    // Inactive: no node, no useDefault — contributes nothing.
                    'ghost' => [],
                    // Active but not on the block layout — silently skipped.
                    'absent' => ['node' => 'seasons.year'],
                ],
            ],
        ]));

        $this->assertCount(1, $blocks);
        $this->assertSame(['year' => 'coerced:2020'], $blocks['new1']['fields']);
    }

    public function testRaggedListsLeaveTrailingBlockMissingTheShorterKey(): void
    {
        $item = new RemoteItem([
            'a' => [1, 2, 3],
            'b' => ['x', 'y'],
        ]);

        $blocks = $this->strategy(['season' => ['year', 'notes']])->parse($this->context($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'a'],
                    'notes' => ['node' => 'b'],
                ],
            ],
        ]));

        $this->assertCount(3, $blocks);
        $this->assertArrayHasKey('notes', $blocks['new2']['fields']);
        $this->assertArrayNotHasKey('notes', $blocks['new3']['fields']);
        $this->assertSame('coerced:3', $blocks['new3']['fields']['year']);
    }

    public function testAddressedIsFalseWhenNoChildAddressesTheItem(): void
    {
        $item = new RemoteItem(['other' => 'x']);
        $context = $this->context($item, [
            'season' => ['fields' => ['year' => ['node' => 'seasons.year']]],
        ]);

        $this->assertFalse($this->strategy(['season' => ['year']])->addressed($context));
    }

    public function testAddressedIsTrueWhenOneChildAddressesTheItem(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->context($item, [
            'season' => [
                'fields' => [
                    'year'  => ['node' => 'seasons.year'],
                    'notes' => ['node' => 'seasons.summary'],
                ],
            ],
        ]);

        $this->assertTrue($this->strategy(['season' => ['year', 'notes']])->addressed($context));
    }

    public function testAddressedIsTrueWhenChildInASecondTypeAddressesTheItem(): void
    {
        // The first type addresses nothing; the second does — addressed() must
        // scan every configured block-type tree.
        $item = new RemoteItem(['quotes' => [['text' => 'hi']]]);
        $context = $this->context($item, [
            'season' => ['fields' => ['year' => ['node' => 'seasons.year']]],
            'quote'  => ['fields' => ['text' => ['node' => 'quotes.text']]],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $this->assertTrue($strategy->addressed($context));
    }

    public function testAllChildrenEmptyReturnsExplicitClear(): void
    {
        // seasons present but every mapped node resolves to null → the feed
        // spoke (addressed) but had nothing; full-replace clears the field.
        $item = new RemoteItem(['seasons' => [['other' => 1]], 'year' => '']);

        $context = $this->context($item, [
            'season' => ['fields' => ['year' => ['node' => 'year']]],
        ]);
        $strategy = $this->strategy(['season' => ['year']]);

        // addressedBy is true for an explicit empty-string node value…
        $this->assertTrue($strategy->addressed($context));
        // …but resolve() returns null for it, so no block contributes.
        $this->assertSame([], $strategy->parse($context));
    }

    public function testUnknownBlockTypeThrows(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->context($item, [
            'nope' => ['fields' => ['year' => ['node' => 'seasons.year']]],
        ]);

        $this->expectException(MappingValueException::class);
        $this->strategy(['season' => ['year']])->parse($context);
    }

    public function testNativeSubFieldLandsAtBlockTopLevel(): void
    {
        $item = new RemoteItem([
            'seasons' => [
                ['year' => 2020, 'label' => 'First'],
                ['year' => 2021, 'label' => 'Second'],
            ],
        ]);

        $blocks = $this->strategy(['season' => ['year']])->parse($this->context($item, [
            'season' => [
                'fields'       => ['year' => ['node' => 'seasons.year']],
                'nativeFields' => ['title' => ['node' => 'seasons.label']],
            ],
        ]));

        $this->assertSame('First', $blocks['new1']['title']);
        $this->assertSame('Second', $blocks['new2']['title']);
        $this->assertArrayNotHasKey('title', $blocks['new1']['fields']);
    }

    // -- multi-type behaviour -------------------------------------------------

    public function testMultipleTypesGroupInFieldDeclaredOrderWithContinuousCounter(): void
    {
        // Config declares quote before season, but the FIELD declares
        // season → quote, so blocks group in field order with new1..newN
        // running continuously across the two types.
        $item = new RemoteItem([
            'seasons' => [['year' => 2020], ['year' => 2021]],
            'quotes'  => [['text' => 'q1']],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context($item, [
            'quote'  => ['fields' => ['text' => ['node' => 'quotes.text']]],
            'season' => ['fields' => ['year' => ['node' => 'seasons.year']]],
        ]));

        $this->assertSame(['new1', 'new2', 'new3'], array_keys($blocks));
        $this->assertSame('season', $blocks['new1']['type']);
        $this->assertSame('season', $blocks['new2']['type']);
        $this->assertSame('quote', $blocks['new3']['type']);
        $this->assertSame('coerced:2020', $blocks['new1']['fields']['year']);
        $this->assertSame('coerced:q1', $blocks['new3']['fields']['text']);
    }

    public function testFieldTypeWithoutConfiguredEntryEmitsNoBlocks(): void
    {
        // `quote` is a real field type but the mapping never configures it —
        // no quote blocks are emitted.
        $item = new RemoteItem([
            'seasons' => [['year' => 2020]],
            'quotes'  => [['text' => 'ignored']],
        ]);

        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);
        $blocks = $strategy->parse($this->context($item, [
            'season' => ['fields' => ['year' => ['node' => 'seasons.year']]],
        ]));

        $this->assertCount(1, $blocks);
        $this->assertSame('season', $blocks['new1']['type']);
    }

    public function testPerTypeMappedHandleIsolation(): void
    {
        // The same child handle `label` is mapped on both types to different
        // nodes — each type's blocks read only its own mapping.
        $item = new RemoteItem([
            'seasons' => [['name' => 'S1']],
            'quotes'  => [['author' => 'A1']],
        ]);

        $strategy = $this->strategy(['season' => ['label'], 'quote' => ['label']]);
        $blocks = $strategy->parse($this->context($item, [
            'season' => ['fields' => ['label' => ['node' => 'seasons.name']]],
            'quote'  => ['fields' => ['label' => ['node' => 'quotes.author']]],
        ]));

        $this->assertSame('coerced:S1', $blocks['new1']['fields']['label']);
        $this->assertSame('season', $blocks['new1']['type']);
        $this->assertSame('coerced:A1', $blocks['new2']['fields']['label']);
        $this->assertSame('quote', $blocks['new2']['type']);
    }

    // -- change detection -----------------------------------------------------

    public function testValueDiffersIsFalseForIdenticalCurrentAndIncoming(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->context($item, ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('season', ['year' => 'coerced:2021']),
        ]);

        $this->assertFalse($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueWhenAChildValueDiffers(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->context($item, ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('season', ['year' => 'coerced:9999']),
        ]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueOnBlockCountMismatch(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020], ['year' => 2021]]]);
        $context = $this->context($item, ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([$this->fakeBlock('season', ['year' => 'coerced:2020'])]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersIsTrueWhenCurrentHoldsAnUnconfiguredType(): void
    {
        // Incoming has one configured season block; the current field also
        // carries a block of an UNconfigured type. The feed is authoritative —
        // the comparison must differ so the replace drops the stray block.
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->context($item, ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]]);
        $strategy = $this->strategy(['season' => ['year'], 'quote' => ['text']]);

        $incoming = $strategy->parse($context);
        $current = $this->fakeQuery([
            $this->fakeBlock('season', ['year' => 'coerced:2020']),
            $this->fakeBlock('quote', ['text' => 'leftover']),
        ]);

        $this->assertTrue($strategy->exposedValueDiffers($context, $current, $incoming));
    }

    public function testValueDiffersFallsBackToParentForNonQueryCurrent(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->context($item, ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]]);
        $strategy = $this->strategy(['season' => ['year']]);

        $incoming = $strategy->parse($context);

        // A scalar current can't be a block query — parent normalise/compare
        // decides, and a scalar vs. array always differs.
        $this->assertTrue($strategy->exposedValueDiffers($context, 'not-a-query', $incoming));
    }

    public function testDescendPastMaxDepthThrows(): void
    {
        $item = new RemoteItem(['seasons' => [['year' => 2020]]]);
        $context = $this->context(
            $item,
            ['season' => ['fields' => ['year' => ['node' => 'seasons.year']]]],
            FieldContext::MAX_DEPTH,
        );
        $strategy = $this->strategy(['season' => ['year']]);

        $this->expectException(MappingDepthException::class);
        $strategy->parse($context);
    }

    public function testHashInsertRoundTripsThroughRemoteItemForNumericSegments(): void
    {
        $item = new RemoteItem(Hash::insert([], 'items.0.name', 'x'));
        $this->assertSame('x', $item->get('items.0.name'));

        $flat = new RemoteItem(Hash::insert([], 'seasons.year', 2020));
        $this->assertSame(2020, $flat->get('seasons.year'));
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * A Matrix strategy with block-type discovery, throwaway-element creation
     * and child-strategy lookup stubbed out. Records the FieldContext each
     * child receives (for assertions) and coerces child values to
     * `coerced:<value>` so per-block resolution is observable.
     *
     * @param array<string, list<string>> $typeLayouts block-type handle (in
     * declared order) → the handles that type's fake layout exposes
     */
    protected function strategy(array $typeLayouts): Matrix
    {
        $test = $this;

        return new class($typeLayouts, $test) extends Matrix {
            /** @var array<string, list<string>> */
            public array $typeLayouts = [];

            public MatrixFieldTest $test;

            /** @var array<string, list<FieldContext>> */
            public array $recordedContexts = [];

            public function __construct(array $typeLayouts, MatrixFieldTest $test)
            {
                $this->typeLayouts = $typeLayouts;
                $this->test = $test;
            }

            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }

            protected function blockTypeHandles(FieldContext $context): array
            {
                return array_keys($this->typeLayouts);
            }

            protected function blockElement(FieldContext $context, string $typeHandle): ?ElementInterface
            {
                return $this->test->fakeBlockElement($this->typeLayouts[$typeHandle] ?? []);
            }

            protected function childStrategy(CraftFieldInterface $childCraftField): Field
            {
                $strategy = $this;

                return new class($strategy) extends Field {
                    public $owner;

                    public function __construct($owner)
                    {
                        $this->owner = $owner;
                    }

                    public function parse(FieldContext $context): mixed
                    {
                        $this->owner->recordedContexts[$context->handle][] = $context;

                        return 'coerced:' . $context->mapping->resolve($context->item);
                    }
                };
            }
        };
    }

    /**
     * A throwaway block element whose field layout exposes a mock craft field
     * for each of the given handles.
     *
     * @param list<string> $handles
     */
    public function fakeBlockElement(array $handles): ElementInterface
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->willReturnCallback(
            function(string $handle) use ($handles): ?CraftFieldInterface {
                return in_array($handle, $handles, true)
                    ? $this->createMock(CraftFieldInterface::class)
                    : null;
            },
        );

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);

        return $element;
    }

    /**
     * A fake current block exposing getType()->handle and
     * getSerializedFieldValues() the way currentFingerprint() reads them. A
     * plain object (not an ElementInterface mock) because those methods aren't
     * on the interface — currentFingerprint() types its block as `object`.
     *
     * @param array<string, mixed> $serialized
     */
    public function fakeBlock(string $typeHandle, array $serialized): object
    {
        $type = new class($typeHandle) {
            public string $handle;

            public function __construct(string $handle)
            {
                $this->handle = $handle;
            }
        };

        return new class($type, $serialized) {
            public object $type;

            /** @var array<string, mixed> */
            public array $serialized;

            public function __construct(object $type, array $serialized)
            {
                $this->type = $type;
                $this->serialized = $serialized;
            }

            public function getType(): object
            {
                return $this->type;
            }

            /**
             * @param list<string>|null $handles
             * @return array<string, mixed>
             */
            public function getSerializedFieldValues(?array $handles = null): array
            {
                if ($handles === null) {
                    return $this->serialized;
                }

                return array_intersect_key($this->serialized, array_flip($handles));
            }
        };
    }

    /**
     * A fake element query exposing all() over the given blocks.
     *
     * @param list<object> $blocks
     */
    public function fakeQuery(array $blocks): object
    {
        return new class($blocks) {
            /** @var list<object> */
            public array $blocks;

            public function __construct(array $blocks)
            {
                $this->blocks = $blocks;
            }

            public function all(): array
            {
                return $this->blocks;
            }
        };
    }

    /**
     * A Matrix top-level FieldContext. `$blocks` is the per-block-type
     * sub-mapping tree map ({typeHandle: {fields, nativeFields}}), wrapped into
     * the mapping's `blocks` channel.
     *
     * @param array<string, mixed> $blocks
     */
    protected function context(RemoteItem $item, array $blocks, int $depth = 0): FieldContext
    {
        return new FieldContext(
            craftField: $this->createMock(CraftFieldInterface::class),
            handle: 'seasons',
            mapping: FieldMapping::fromConfig('seasons', ['blocks' => $blocks]),
            item: $item,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            depth: $depth,
        );
    }
}
