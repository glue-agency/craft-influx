<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix as CraftMatrixField;
use craft\models\FieldLayout;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\enums\MatrixBlockSource;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use Throwable;

/**
 * Mapping strategy for Craft's Matrix field. Turns ONE remote list into Matrix
 * blocks across ANY of the field's block types (Feed Me-style) — the mapping
 * carries a per-block-type sub-mapping tree under a `blocks` channel
 * ({@see FieldMapping::blockMappings()}), keyed by block-type handle. Each
 * entry is itself a node-less FieldMapping-shaped config
 * (`{fields: {...}, nativeFields: {...}}`) whose child node paths are RELATIVE
 * to one element of that list.
 *
 * The persisted shape:
 *
 *   mappings[<matrixHandle>] = {
 *       node: 'content_blocks',
 *       options: { blockSource: 'listByKey' },
 *       blocks: {
 *           text:  { fields: { body: { node: 'body' } } },
 *           quote: { fields: { text: { node: 'quote' } },
 *                    nativeFields: { title: { node: 'author' } } },
 *       },
 *   }
 *
 * The row's node is the list; {@see RemoteItem::each()} reads it positionally
 * and one block is emitted per element IN FEED ORDER, with a continuous `newN`
 * counter (new1, new2, … global). Only active children
 * ({@see FieldMapping::isActive()}) contribute, and a child absent from an
 * element simply leaves its key off THAT block — nothing shifts. Each child
 * value is coerced through its own strategy via a synthetic single-value
 * {@see RemoteItem} + {@see FieldContext::descend()}.
 *
 * How an element names its block type is the row's `options.blockSource`
 * ({@see MatrixBlockSource}) — its own key, a discriminator node, or not at all.
 * An element naming no mapped type is SKIPPED: a feed carrying a type the link
 * doesn't map is data, not a broken mapping. A misconfigured ROW does throw — a
 * source with no node, LIST_SINGLE with more than one type mapped, or a node
 * holding something that isn't a list.
 *
 * Note what this deliberately can't do: build blocks out of lists in UNRELATED
 * parts of an item. An earlier engine could, off absolute per-type paths, but
 * the field's declared type order decided its output — so a feed carrying
 * `text, quote, text` got `text, text, quote` and no feed shape could ask for
 * otherwise. Blocks come from one list now, and the list decides the order.
 *
 * Extends {@see Field} directly (NOT
 * {@see DefaultField}, NOT {@see RelationalField}): it neither writes related
 * ids nor persists sub-elements — it builds Craft's flat serialized Matrix
 * value shape (`['new1' => ['type' => …, 'enabled' => true, 'fields' => […]]]`),
 * which the inherited {@see apply()} hands to `setFieldValue`. Blocks are only
 * ever persisted by the OWNER element's save, so {@see parse()} is
 * dry-run-safe by construction: it creates nothing, saves nothing, and coerces
 * child values through their own strategies purely in memory.
 *
 * Sync semantics are full-replace: every incoming block gets a fresh `newN`
 * key, so a sync rebuilds the field's blocks from the feed.
 * {@see valueDiffers()} compares a mapped-keys-only fingerprint so an
 * unchanged feed never triggers a destructive replace — the mapped handle sets
 * are resolved PER TYPE, and a current block of a type the feed doesn't
 * configure fingerprints on its type alone, so it always reads as a difference
 * (the feed is authoritative; the replace that drops it converges on the next
 * run). Those same fingerprints drive the inspectors' per-block drill-down
 * ({@see collectChildren()}) — a read-only derivation ALONGSIDE change
 * detection, never part of it. A real run then zips the blocks its save created
 * back onto those children ({@see attachSavedChildren()}), which is the only way
 * an added block can be chipped: at derivation time it isn't an element yet.
 *
 * Both fingerprints are ordered lists compared whole, so a feed that only
 * REORDERS its blocks reads as a difference and is written through — which is
 * the whole point of reading the list positionally.
 */
class Matrix extends Field
{
    /**
     * A Matrix holds nested entries, not a value — its field value is an element query, so there is nothing for a feed to match on.
     * See {@see Field::matchable()}.
     */
    public static function matchable(): bool
    {
        return false;
    }

    public static function craftFieldClass(): ?string
    {
        return CraftMatrixField::class;
    }

    /**
     * One always-visible card per block type, each reading and writing its own
     * `blocks.<handle>` slice; a block type with no mappable sub-fields at all
     * still gets a card.
     *
     * A block type that exposes a native Title leads with a title row, ahead of
     * the custom fields, in the same order {@see buildBlock()} fills a
     * block in. Rows carry an optional `channel` key that routes where the SPA
     * writes them: `nativeFields` for the title, ABSENT for a custom field —
     * absent means the `fields` channel, which is the stored shape that predates
     * the key.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            // The source cell is the list the blocks are built from. No default
            // cell: a whole set of blocks isn't a value to type into a box.
            'source'  => true,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) use ($field) {
                $blockTypes = $this->blockTypeDescriptors($field);

                if (! $blockTypes) {
                    return $b
                        ->note(['text' => Craft::t('influx', 'This Matrix field has no block types to map yet.')]);
                }

                $this->blockSourceNodes($b, $blockTypes);

                $builder = $b;

                foreach ($blockTypes as $blockType) {
                    $subFields = MappingSchemaBuilder::make();
                    $layout = $blockType['layout'];

                    if ($blockType['hasTitleField']) {
                        $subFields->text([
                            'handle'  => 'title',
                            'label'   => $this->titleLabel($layout),
                            'channel' => 'nativeFields',
                        ]);
                    }

                    foreach ($layout !== null ? $layout->getCustomFields() : [] as $customField) {
                        $subFields->fieldRow($this->childRowFor($customField), [
                            'handle' => $customField->handle,
                            'label'  => $customField->name,
                        ]);
                    }

                    $builder->matrixFields([
                        'label'     => $blockType['name'],
                        'subFields' => $subFields->toArray(),
                        'blockType' => $blockType['handle'],
                    ]);
                }

                return $builder;
            },
        ]);
    }

    /**
     * The settings that pick a block source and feed it: the source itself, a
     * worked example of the shape it expects, the discriminator node
     * LIST_BY_NODE reads, and one feed-alias box per block type for the two
     * sources that match a key.
     *
     * The example is one showIf-gated note per source rather than prose on the
     * select, because the question a developer actually has here is "what does
     * my JSON have to look like", and three lines of it answer that faster than
     * a paragraph describing it. Notes bind nothing, so gating them is free.
     *
     * The aliases are gated on the two key-matching sources
     * ({@see MatrixBlockSource::matchesKey()}), and they're one flat option per
     * type ({@see sourceKeyOption()}) rather than one nested map, since an
     * extras leaf binds exactly one option key.
     *
     * @param list<array{handle: string, name: string, layout: ?FieldLayout, hasTitleField: bool}> $blockTypes
     */
    protected function blockSourceNodes(MappingSchemaBuilder $b, array $blockTypes): void
    {
        $b->select([
            'handle'  => 'blockSource',
            'label'   => Craft::t('influx', 'Data type'),
            'options' => [
                [
                    'value' => MatrixBlockSource::LIST_BY_KEY->value,
                    'label' => Craft::t('influx', 'A list, each item keyed by its block type'),
                ],
                [
                    'value' => MatrixBlockSource::LIST_BY_NODE->value,
                    'label' => Craft::t('influx', 'A list, each item naming its block type in a node'),
                ],
                [
                    'value' => MatrixBlockSource::LIST_SINGLE->value,
                    'label' => Craft::t('influx', 'A list, all of one block type'),
                ],
            ],
            'default'      => MatrixBlockSource::fallback()->value,
            'instructions' => Craft::t(
                'influx',
                'Blocks are built from the list in the source cell, one per item, in the feed’s own order. '
                . 'The sub-field paths below are relative to ONE item (<code>image</code>), not to the whole item.',
            ),
        ]);

        foreach ($this->blockSourceExamples($blockTypes) as $source => $example) {
            $b->note([
                'text'    => Craft::t('influx', 'The feed shape this expects:'),
                'example' => $example,
                'showIf'  => [['handle' => 'blockSource', 'equals' => $source]],
            ]);
        }

        $b->text([
            'handle'       => 'typeNode',
            'label'        => Craft::t('influx', 'Block type node'),
            'default'      => 'type',
            'instructions' => Craft::t('influx', 'The path, within one list item, naming its block type.'),
            'showIf'       => [
                ['handle' => 'blockSource', 'equals' => MatrixBlockSource::LIST_BY_NODE->value],
            ],
        ]);

        $keyed = array_map(
            static fn(MatrixBlockSource $source): string => $source->value,
            array_filter(
                MatrixBlockSource::cases(),
                static fn(MatrixBlockSource $source): bool => $source->matchesKey(),
            ),
        );

        foreach ($blockTypes as $blockType) {
            $b->text([
                'handle'       => self::sourceKeyOption($blockType['handle']),
                'label'        => Craft::t('influx', 'Feed key for “{name}”', ['name' => $blockType['name']]),
                'default'      => $blockType['handle'],
                'instructions' => Craft::t('influx', 'What the feed calls this block type, if not its handle.'),
                'showIf'       => [['handle' => 'blockSource', 'in' => array_values($keyed)]],
            ]);
        }
    }

    /**
     * A worked feed snippet per block source, written in the FIELD'S OWN block
     * types and their real sub-field handles — a generic `{"type": "text"}` makes
     * the reader translate, and translating is where the shape gets misread.
     *
     * Two types and two sub-fields is the most that stays scannable in a hint,
     * so longer layouts are elided with `…` rather than printed whole.
     *
     * @param list<array{handle: string, name: string, layout: ?FieldLayout, hasTitleField: bool}> $blockTypes
     * @return array<string, string>
     */
    protected function blockSourceExamples(array $blockTypes): array
    {
        $first = $blockTypes[0];
        $second = $blockTypes[1] ?? null;
        $firstFields = $this->exampleFieldHandles($first);
        $secondFields = $this->exampleFieldHandles($second ?? $first);

        $pairs = static fn(array $handles): string => implode(', ', array_map(
            static fn(string $handle): string      => "\"{$handle}\": \"…\"",
            $handles,
        ));

        $keyed = ["  { \"{$first['handle']}\": { {$pairs($firstFields)} } },"];
        $noded = ["  { \"type\": \"{$first['handle']}\", {$pairs($firstFields)} },"];

        if ($second !== null) {
            $keyed[] = "  { \"{$second['handle']}\": { {$pairs($secondFields)} } },";
            $noded[] = "  { \"type\": \"{$second['handle']}\", {$pairs($secondFields)} },";
        }

        $keyed[] = "  { \"{$first['handle']}\": { {$pairs($firstFields)} } }";
        $noded[] = "  { \"type\": \"{$first['handle']}\", {$pairs($firstFields)} }";

        $single = [
            "  { {$pairs($firstFields)} },",
            "  { {$pairs($firstFields)} }",
        ];

        return [
            MatrixBlockSource::LIST_BY_KEY->value  => $this->exampleList($keyed),
            MatrixBlockSource::LIST_BY_NODE->value => $this->exampleList($noded),
            MatrixBlockSource::LIST_SINGLE->value  => $this->exampleList($single),
        ];
    }

    /**
     * Up to two mappable sub-field handles of one block type — enough to show
     * where a block's values sit without printing a whole layout. A type with no
     * mappable fields at all still needs something between the braces, so it
     * borrows the generic name.
     *
     * @param array{handle: string, layout: ?FieldLayout, hasTitleField: bool} $blockType
     * @return list<string>
     */
    protected function exampleFieldHandles(array $blockType): array
    {
        $handles = $blockType['hasTitleField'] ? ['title'] : [];

        foreach ($blockType['layout']?->getCustomFields() ?? [] as $customField) {
            $handles[] = $customField->handle;
        }

        return array_slice($handles, 0, 2) ?: ['field'];
    }

    /**
     * The rows wrapped as the list the row's source node points at.
     *
     * @param list<string> $rows
     */
    protected function exampleList(array $rows): string
    {
        return "[\n" . implode("\n", $rows) . "\n]";
    }

    /**
     * The label a block type's native Title row carries: what the editor sees on
     * the block itself — the layout's title element can be relabelled per type —
     * falling back to Craft's own "Title". Mirrors how {@see \GlueAgency\Influx\targets\EntryTarget::matchableNativeAttributes()}
     * labels the entry-level title option.
     */
    protected function titleLabel(?FieldLayout $layout): string
    {
        $titleElement = $layout?->getFirstElementByType(EntryTitleField::class);

        return $titleElement?->label() ?: Craft::t('app', 'Title');
    }


    /**
     * The row is addressed by its OWN node: its child nodes are relative to a
     * list element, so resolving them against the whole item answers nothing.
     * It still needs one active child somewhere — a row that names a list but
     * maps nothing out of it is half-built, and clearing the field over that
     * would be the destructive reading of an operator's unfinished work. Note
     * that a PRESENT but EMPTY list IS addressed: the feed is explicitly
     * carrying no blocks, so {@see parse()} returns the empty value that clears
     * the field.
     */
    public function addressed(FieldContext $context): bool
    {
        return $context->mapping->addressedBy($context->item)
            && $this->hasActiveChildren($context);
    }

    /**
     * Whether ANY mapped block type has an active child at all —
     * item-independent, unlike the per-item test {@see addressed()} pairs it
     * with.
     */
    protected function hasActiveChildren(FieldContext $context): bool
    {
        foreach ($context->mapping->blockMappings() as $typeMapping) {
            if ($this->activeChildren($typeMapping) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row's configured block source, falling back to
     * {@see MatrixBlockSource::fallback()} for both an unset option and an
     * unrecognised one.
     */
    protected function blockSource(FieldContext $context): MatrixBlockSource
    {
        $stored = $context->mapping->option('blockSource');

        if (! is_string($stored)) {
            return MatrixBlockSource::fallback();
        }

        return MatrixBlockSource::tryFrom($stored) ?? MatrixBlockSource::fallback();
    }

    /**
     * Build the flat serialized Matrix value: one block per element of the row's
     * list node, in feed order. An empty result is still returned as an explicit
     * clear rather than null — {@see addressed()} was true, so the feed is
     * authoritative even when it carried no blocks at all.
     *
     * An element that names no mapped block type is skipped: a feed carrying a
     * type the link doesn't map is data, not misconfiguration, and failing the
     * whole item over it would let one unmapped type take an entire sync down.
     * A row that can't be read at all IS misconfiguration, and throws.
     *
     * @throws MappingValueException when a mapped block-type handle is unknown
     * for the field, a throwaway block can't be built, or the row is
     * misconfigured / points at something that isn't a list
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    public function parse(FieldContext $context): mixed
    {
        $configured = $context->mapping->blockMappings();

        $fieldHandles = $this->blockTypeHandles($context);

        foreach (array_keys($configured) as $typeHandle) {
            if (! in_array($typeHandle, $fieldHandles, true)) {
                throw new MappingValueException(
                    "Matrix mapping '{$context->handle}' has an unknown block type '{$typeHandle}'.",
                );
            }
        }

        return $this->parseList($context, $this->blockSource($context), $configured);
    }

    /**
     * One block per element of the row's list node, in feed order. An element
     * that names no configured block type is skipped — a feed carrying a type
     * the link doesn't map is data, not misconfiguration, and failing the whole
     * item over it would make one unmapped block type take an entire sync down.
     * A row that can't be read at all IS misconfiguration, and throws.
     *
     * @param array<string, FieldMapping> $configured
     * @return array<string, mixed>
     * @throws MappingValueException
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    protected function parseList(FieldContext $context, MatrixBlockSource $source, array $configured): array
    {
        $node = $context->mapping->node;

        if ($node === null) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' reads its blocks from a list, so it needs a source node.",
            );
        }

        if ($source === MatrixBlockSource::LIST_SINGLE && count($configured) > 1) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' maps more than one block type, so its list must say which "
                . 'type each element is.',
            );
        }

        $elements = $context->item->each($node);

        if ($elements === null) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' expected a list of blocks at '{$node}'.",
            );
        }

        $blocks = [];
        $carriers = [];
        $index = 0;

        foreach ($elements as $element) {
            $assigned = $this->assignType($context, $source, $configured, $element);

            if ($assigned === null) {
                continue;
            }

            $typeHandle = $assigned['handle'];

            if (! array_key_exists($typeHandle, $carriers)) {
                $carriers[$typeHandle] = $this->blockElement($context, $typeHandle);
            }

            if ($carriers[$typeHandle] === null) {
                throw new MappingValueException(
                    "Matrix mapping '{$context->handle}' could not build a block of type '{$typeHandle}'.",
                );
            }

            $index++;
            $blocks['new' . $index] = $this->buildBlock(
                $context,
                $typeHandle,
                $configured[$typeHandle],
                $carriers[$typeHandle],
                $assigned['item'],
            );
        }

        return $blocks;
    }

    /**
     * Which block type one list element is, and the payload its child nodes
     * resolve against — the two together, because LIST_BY_KEY answers them at
     * once: the key names the type AND the value under it is the payload, while
     * the other two sources leave the element itself as the payload.
     *
     * Null means "no configured type claims this element", which the caller
     * skips. LIST_BY_KEY walks the ELEMENT's keys rather than the configured
     * types so an element carrying metadata beside its payload (`{"id": 4182,
     * "text": {...}}`) still resolves, and so the first key an element declares
     * wins when two of them name types.
     *
     * @param array<string, FieldMapping> $configured
     * @return array{handle: string, item: RemoteItem}|null
     */
    protected function assignType(
        FieldContext $context,
        MatrixBlockSource $source,
        array $configured,
        RemoteItem $element,
    ): ?array {
        if ($source === MatrixBlockSource::LIST_SINGLE) {
            $handle = array_key_first($configured);

            return $handle !== null ? ['handle' => $handle, 'item' => $element] : null;
        }

        if ($source === MatrixBlockSource::LIST_BY_NODE) {
            $value = $element->get((string) $context->mapping->option('typeNode', 'type'));

            if (! is_string($value) && ! is_numeric($value)) {
                return null;
            }

            $handle = $this->typeForSourceKey($context, $configured, (string) $value);

            return $handle !== null ? ['handle' => $handle, 'item' => $element] : null;
        }

        foreach ($element->raw() as $key => $payload) {
            if (! is_string($key) || ! is_array($payload)) {
                continue;
            }

            $handle = $this->typeForSourceKey($context, $configured, $key);

            if ($handle !== null) {
                return ['handle' => $handle, 'item' => new RemoteItem($payload)];
            }
        }

        return null;
    }

    /**
     * The configured block type a feed key names, matched on each type's
     * {@see sourceKeyOption()} — the alias hook for a feed that spells a type
     * differently from Craft (Storyblok's `component`, Sanity's `_type`),
     * defaulting to the handle itself so a feed that agrees with Craft
     * configures nothing.
     *
     * @param array<string, FieldMapping> $configured
     */
    protected function typeForSourceKey(FieldContext $context, array $configured, string $key): ?string
    {
        foreach (array_keys($configured) as $typeHandle) {
            $alias = $context->mapping->option(self::sourceKeyOption($typeHandle), $typeHandle);

            if (is_scalar($alias) && (string) $alias === $key) {
                return $typeHandle;
            }
        }

        return null;
    }

    /**
     * The mapping-option key holding one block type's feed alias. Flat and
     * prefixed rather than a nested `sourceKeys` map because the SPA writes an
     * extras leaf to `options[<handle>]` verbatim, and {@see FieldMapping::option()}
     * reads dot paths — a dotted handle would be written flat and read nested.
     */
    protected static function sourceKeyOption(string $typeHandle): string
    {
        return 'sourceKey_' . $typeHandle;
    }

    /**
     * One block, built from one list element. The same row shape and the same
     * per-child coercion the row shape needs. A child absent from THIS element
     * leaves its key off THIS block and nothing shifts, and a child handle the
     * block type's layout doesn't expose is skipped silently.
     *
     * @return array<string, mixed>
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    protected function buildBlock(
        FieldContext $context,
        string $typeHandle,
        FieldMapping $typeMapping,
        ElementInterface $carrier,
        RemoteItem $element,
    ): array {
        $layout = $carrier->getFieldLayout();

        $row = [
            'type'    => $typeHandle,
            'enabled' => true,
        ];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $value = $sub->resolve($element);

            if ($value !== null) {
                $row[$sub->handle] = (string) $value;
            }
        }

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $value = $sub->resolve($element);

            if ($value === null) {
                continue;
            }

            $childCraftField = $layout?->getFieldByHandle($sub->handle);

            if ($childCraftField === null) {
                continue;
            }

            $row['fields'][$sub->handle] = $this->coerceChildValue(
                $context,
                $carrier,
                $sub,
                $childCraftField,
                $value,
            );
        }

        return $row;
    }

    /**
     * Full-replace fingerprint comparison, restricted to the mapped child
     * handles so an unchanged feed never triggers a destructive rebuild. The
     * mapped handle sets are PER TYPE: each incoming row is fingerprinted with
     * its own type's mapped handles, and each current block with its type's
     * mapped handles when that type is configured. A current block of a type
     * the feed doesn't configure fingerprints on its type alone — so it never
     * matches an incoming block and the comparison differs, dropping it on the
     * replace (the feed is authoritative).
     *
     * `$incoming` is the parsed blocks array (or []/null); `$current` is the
     * field's current value — an element query. A non-query current (or one
     * that can't be walked) falls back to the base normalise-and-compare, and
     * a throwing current read lands on {@see Field::hasChanged()}'s
     * "assume changed" guard.
     *
     * Leaf values are normalised by the CHILD strategy that owns them
     * ({@see childLeaves()}), not by this one: the two sides of a leaf arrive in
     * different shapes — parsed on the incoming side, Craft-serialized on the
     * stored side — and only the owning strategy knows how to reconcile them
     * ({@see Date::normalize()}).
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        if (! is_object($current) || ! method_exists($current, 'all')) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        $leavesByType = [];
        $nativeByType = [];

        foreach ($context->mapping->blockMappings() as $typeHandle => $typeMapping) {
            $leavesByType[$typeHandle] = $this->childLeaves(
                $context,
                $typeHandle,
                array_keys($this->activeCustomHandles($typeMapping)),
            );
            $nativeByType[$typeHandle] = array_keys($this->activeNativeHandles($typeMapping));
        }

        $incomingBlocks = is_array($incoming) ? array_values($incoming) : [];

        $incomingPrint = [];

        foreach ($incomingBlocks as $row) {
            $type = (string) ($row['type'] ?? '');
            $incomingPrint[] = $this->incomingFingerprint(
                $row,
                $leavesByType[$type] ?? [],
                $nativeByType[$type] ?? [],
            );
        }

        $currentPrint = [];

        foreach ($current->all() as $block) {
            $type = $block->getType()->handle;

            $currentPrint[] = $this->currentFingerprint(
                $block,
                $leavesByType[$type] ?? [],
                $nativeByType[$type] ?? [],
            );
        }

        return json_encode($currentPrint) !== json_encode($incomingPrint);
    }

    /**
     * Fingerprint one parsed incoming block row: type, native values, then the
     * ksort'd mapped custom values — every leaf normalised through its own child
     * strategy so it lines up with the current-block fingerprint.
     *
     * Every mapped leaf is printed, missing ones as null. A child resolving to
     * null contributes no per-block value, so {@see buildBlock()} leaves it
     * out of the row entirely — while the current side reads
     * `getSerializedFieldValues()`, which returns each requested handle whether
     * it holds a value or not. Keying on presence would make those two shapes
     * disagree for any nullable leaf and read as a difference on every sync,
     * replacing the blocks each time. Absent means null here: that is what the
     * block holds once saved.
     *
     * @param array<string, mixed> $row
     * @param array<string, ?Field> $customLeaves mapped custom handle → the
     * strategy that normalises that leaf, in mapped order
     * @param list<string> $nativeHandles
     * @return array<string, mixed>
     */
    protected function incomingFingerprint(array $row, array $customLeaves, array $nativeHandles): array
    {
        $print = ['type' => $row['type'] ?? null];

        foreach ($nativeHandles as $handle) {
            $print['native'][$handle] = $this->normalize($row[$handle] ?? null);
        }

        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $print['fields'] = [];

        foreach ($customLeaves as $handle => $leaf) {
            $print['fields'][$handle] = array_key_exists($handle, $fields)
                ? $this->leafNormalize($leaf, $fields[$handle])
                : null;
        }

        ksort($print['fields']);

        return $print;
    }

    /**
     * Fingerprint one current block element, mirroring
     * {@see incomingFingerprint()}. getType()->handle works on both Craft 4
     * MatrixBlock and Craft 5 Entry; only the mapped handles are read. Typed as
     * `object` (not ElementInterface) because neither getType() nor
     * getSerializedFieldValues() is declared on the interface — they live on the
     * concrete block element classes.
     *
     * @param array<string, ?Field> $customLeaves
     * @param list<string> $nativeHandles
     * @return array<string, mixed>
     */
    protected function currentFingerprint(object $block, array $customLeaves, array $nativeHandles): array
    {
        $print = ['type' => $block->getType()->handle];

        foreach ($nativeHandles as $handle) {
            $print['native'][$handle] = $this->normalize($block->{$handle} ?? null);
        }

        $serialized = $block->getSerializedFieldValues(array_keys($customLeaves));
        $print['fields'] = [];

        foreach ($customLeaves as $handle => $leaf) {
            $print['fields'][$handle] = array_key_exists($handle, $serialized)
                ? $this->leafNormalize($leaf, $serialized[$handle])
                : null;
        }

        ksort($print['fields']);

        return $print;
    }

    /**
     * Blocks — the noun the inspectors count this row's children with.
     */
    public function childrenKind(): ?string
    {
        return 'blocks';
    }

    /**
     * Per-block drill-down for this row, derived from the value the field is
     * receiving and the blocks the element still held before it. Read-only: it
     * persists nothing and touches neither the parsed value nor the element, so
     * it behaves the same on a dry run (where nothing was applied) as on a real
     * one.
     *
     * Pairing runs two passes over the same per-block fingerprints
     * {@see valueDiffers()} compares. The EXACT pass walks the incoming rows in
     * order and lets each consume the first unconsumed current block with an
     * identical fingerprint — that block comes out of the sync as it went in, so
     * its child reads UNCHANGED. The POSITIONAL pass then hands every remaining
     * row the first unconsumed block OF ITS TYPE, in current order, as a
     * comparison partner (possibly none), and reads ADDED. There is deliberately
     * no UPDATED for blocks: the sync is full-replace, so even a
     * paired-but-different block is an add — the partner only supplies the
     * Current column and the per-field changed flags. Current blocks nobody
     * consumed follow the incoming ones as REMOVED: in the element, not in the
     * feed.
     *
     * Accepted cost: this re-fingerprints what {@see hasChanged()} already
     * fingerprinted. Both walks are bounded by the block count, and deriving the
     * drill-down purely from the two values it is handed is what keeps it
     * independent of whether — and how — the change check ran.
     *
     * A child is labelled by the block's OWN title ({@see childTitle()}), not by
     * its type — and carries that block as its element identity whenever one
     * exists: an UNCHANGED child's partner and a REMOVED child's block are saved
     * elements, so the drill-down can chip and link straight to them. An ADDED
     * child has no saved element yet, even when a partner supplied its Current
     * column, so it carries none.
     *
     * @param mixed $incoming the parsed blocks array
     * @param mixed $current the field's value from before apply() — a block query
     * @return list<ChildResult>|null
     */
    public function collectChildren(FieldContext $context, mixed $incoming, mixed $current): ?array
    {
        if (! is_array($incoming)) {
            return null;
        }

        $typeMappings = $context->mapping->blockMappings();

        if ($typeMappings === []) {
            return null;
        }

        $rows = array_values($incoming);
        $blocks = $this->currentBlocks($current);

        if ($rows === [] && $blocks === []) {
            return null;
        }

        $mapped = $this->mappedLeaves($context);
        $pairing = $this->pairBlocks($rows, $blocks, $mapped);

        $elementsByType = $this->listElementsByType($context, $this->blockSource($context), $typeMappings);

        $children = [];
        $ordinals = [];
        $lists = [];
        $labels = [];

        foreach ($rows as $i => $row) {
            $type = (string) ($row['type'] ?? '');
            $ordinal = $ordinals[$type] ?? 0;
            $ordinals[$type] = $ordinal + 1;

            $partnerIndex = $pairing['partners'][$i] ?? null;
            $partner = $partnerIndex !== null ? $blocks[$partnerIndex] : null;
            $action = $pairing['actions'][$i];
            $typeMapping = $typeMappings[$type] ?? null;
            $results = [];

            if ($typeMapping !== null) {
                if (! isset($lists[$type])) {
                    $lists[$type] = $this->elementLists($typeMapping, $elementsByType[$type] ?? []);
                }

                $results = $this->incomingChildRows(
                    $typeMapping,
                    $row,
                    $this->rawSlice($lists[$type], $ordinal),
                    $partner,
                    $action,
                    $mapped['leaves'][$type] ?? [],
                );
            }

            // Memoized including the null a failed build yields, so a type that
            // no longer resolves isn't retried once per block.
            if (! array_key_exists($type, $labels)) {
                $labels[$type] = $this->labelBlock($context, $type);
            }

            $children[] = new ChildResult(
                title: $this->childTitle($row, $partner),
                blockType: $type,
                element: $action === ChildAction::UNCHANGED ? $this->blockIdentity($partner) : null,
                labelElement: $labels[$type],
                action: $this->childActionLabel($context, $action),
                mappingResults: $results,
            );
        }

        foreach ($pairing['removed'] as $index) {
            $block = $blocks[$index];
            $type = $block->getType()->handle;
            $typeMapping = $typeMappings[$type] ?? null;
            $identity = $this->blockIdentity($block);

            $children[] = new ChildResult(
                title: $this->blockTitle($block),
                blockType: $type,
                element: $identity,
                labelElement: $identity,
                action: $this->childActionLabel($context, ChildAction::REMOVED),
                mappingResults: $typeMapping !== null ? $this->removedChildRows($typeMapping, $block) : [],
            );
        }

        return array_slice($children, 0, self::CHILD_RESULT_LIMIT);
    }

    /**
     * Zip the blocks the owner's save persisted back onto the children that had
     * no identity when they were derived. The sync is full-replace, so every
     * incoming block is built as `newN` and an ADDED child is element-less — and
     * often title-less — at derivation time ({@see collectChildren()}); after the
     * save those very blocks exist, and the log snapshot can chip and link them.
     *
     * Pairing leans on the one ordering guarantee every block source shares:
     * blocks are saved in the order {@see parse()} emitted them, so the nth
     * non-removed child OF A TYPE is the nth saved block of that type. That
     * holds whether the source grouped the types or interleaved them — grouping
     * only decides where a type's run sits among the others, never the order
     * WITHIN it. A REMOVED child stands for a block the
     * replace dropped — it isn't in the saved set, so it neither pairs nor
     * consumes a slot; an already-identified one does stay in the walk, because
     * it occupies a slot and dropping it would shift every later pairing of its
     * type.
     *
     * Per type all-or-nothing: a type whose non-removed child count doesn't match
     * its saved block count is skipped whole. The two only diverge when something
     * the derivation can't see happened — the {@see CHILD_RESULT_LIMIT} cap
     * truncated the children, a listener rewrote the field, the save dropped a
     * block — and a shifted pairing would chip the WRONG element onto a row,
     * which is worse than no chip at all.
     *
     * @param list<ChildResult> $children mutated in place, nulls only
     */
    public function attachSavedChildren(ElementInterface $element, string $handle, array $children): void
    {
        $saved = $this->savedBlocksByType($element, $handle);

        if ($saved === []) {
            return;
        }

        foreach ($this->pairableChildrenByType($children) as $type => $typeChildren) {
            $blocks = $saved[$type] ?? [];

            if (count($typeChildren) !== count($blocks)) {
                continue;
            }

            foreach ($typeChildren as $i => $child) {
                $this->attachSavedBlock($child, $blocks[$i]);
            }
        }
    }

    /**
     * The blocks the element holds NOW, grouped by block-type handle in query
     * order. Walked through the same guard the derivation reads its current
     * blocks with ({@see currentBlocks()}), with the field read itself inside it:
     * this runs against a just-saved element on a console or queue request, and a
     * snapshot nicety must never be the thing that takes the item's log row down.
     *
     * @return array<string, list<object>>
     */
    protected function savedBlocksByType(ElementInterface $element, string $handle): array
    {
        try {
            $grouped = [];

            foreach ($this->currentBlocks($element->getFieldValue($handle)) as $block) {
                $grouped[$block->getType()->handle][] = $block;
            }

            return $grouped;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The children a saved block can pair with, grouped by block type and kept in
     * derivation order: everything the replace wrote, which is every child but
     * the removed ones.
     *
     * @param list<ChildResult> $children
     * @return array<string, list<ChildResult>>
     */
    protected function pairableChildrenByType(array $children): array
    {
        $grouped = [];

        foreach ($children as $child) {
            if ($this->isRemovedChild($child)) {
                continue;
            }

            $grouped[(string) $child->blockType][] = $child;
        }

        return $grouped;
    }

    /**
     * Whether a child stands for a block the replace dropped. Both label
     * flavours, because a child carries the committed value on a real run and the
     * hypothetical one on a dry run ({@see childActionLabel()}) — only committed
     * labels ever reach the back-fill, but reading the flavours off the enum keeps
     * the check honest wherever it's called from.
     */
    protected function isRemovedChild(ChildResult $child): bool
    {
        return $child->action === ChildAction::REMOVED->value
            || $child->action === ChildAction::REMOVED->dryRunLabel();
    }

    /**
     * Fill one child's missing identity from the block it paired with — element,
     * label carrier and title, each only where the derivation left a null. A
     * value it did resolve stands: a mapped native title is what this sync wrote
     * and outranks the stored one ({@see childTitle()}).
     */
    protected function attachSavedBlock(ChildResult $child, object $block): void
    {
        $identity = $this->blockIdentity($block);

        if ($child->element === null) {
            $child->element = $identity;
        }

        if ($child->labelElement === null) {
            $child->labelElement = $identity;
        }

        if ($child->title === null) {
            $child->title = $this->blockTitle($block);
        }
    }

    /**
     * The blocks the element currently holds, or none when they can't be read: a
     * brand-new element's block query has no owner to query for, and the
     * drill-down must never be the thing that takes a row down.
     *
     * @return list<object>
     */
    protected function currentBlocks(mixed $current): array
    {
        if (! is_object($current) || ! method_exists($current, 'all')) {
            return [];
        }

        try {
            return array_values($current->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The per-type comparison machinery the fingerprints and the child rows both
     * read: each configured block type's mapped custom leaves (handle → the
     * strategy that normalises that leaf) and its mapped native handles. Mirrors
     * what {@see valueDiffers()} builds inline for the change check.
     *
     * @return array{leaves: array<string, array<string, ?Field>>, natives: array<string, list<string>>}
     */
    protected function mappedLeaves(FieldContext $context): array
    {
        $mapped = ['leaves' => [], 'natives' => []];

        foreach ($context->mapping->blockMappings() as $typeHandle => $typeMapping) {
            $mapped['leaves'][$typeHandle] = $this->childLeaves(
                $context,
                $typeHandle,
                array_keys($this->activeCustomHandles($typeMapping)),
            );
            $mapped['natives'][$typeHandle] = array_keys($this->activeNativeHandles($typeMapping));
        }

        return $mapped;
    }

    /**
     * Pair incoming rows with current blocks — exact fingerprints first, then
     * positionally within a type ({@see collectChildren()} documents why). Yields,
     * per incoming index, its partner block's index (or null) and its action, plus
     * the indexes of the current blocks nobody consumed, in current order.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<object> $blocks
     * @param array{leaves: array<string, array<string, ?Field>>, natives: array<string, list<string>>} $mapped
     * @return array{partners: array<int, ?int>, actions: array<int, ChildAction>, removed: list<int>}
     */
    protected function pairBlocks(array $rows, array $blocks, array $mapped): array
    {
        $incomingPrints = [];

        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? '');
            $incomingPrints[] = json_encode($this->incomingFingerprint(
                $row,
                $mapped['leaves'][$type] ?? [],
                $mapped['natives'][$type] ?? [],
            ));
        }

        $currentPrints = [];
        $currentTypes = [];

        foreach ($blocks as $block) {
            $type = $block->getType()->handle;
            $currentTypes[] = $type;
            $currentPrints[] = json_encode($this->currentFingerprint(
                $block,
                $mapped['leaves'][$type] ?? [],
                $mapped['natives'][$type] ?? [],
            ));
        }

        $partners = [];
        $actions = [];
        $consumed = [];

        foreach ($incomingPrints as $i => $print) {
            $match = $this->firstUnconsumed($currentPrints, $consumed, $print);

            if ($match === null) {
                continue;
            }

            $consumed[$match] = true;
            $partners[$i] = $match;
            $actions[$i] = ChildAction::UNCHANGED;
        }

        foreach ($rows as $i => $row) {
            if (isset($actions[$i])) {
                continue;
            }

            $match = $this->firstUnconsumed($currentTypes, $consumed, (string) ($row['type'] ?? ''));

            if ($match !== null) {
                $consumed[$match] = true;
            }

            $partners[$i] = $match;
            $actions[$i] = ChildAction::ADDED;
        }

        return [
            'partners' => $partners,
            'actions'  => $actions,
            'removed'  => array_values(array_diff(array_keys($blocks), array_keys($consumed))),
        ];
    }

    /**
     * The list elements each
     * block type claimed, in feed order, so the nth element of a type is the one
     * the nth incoming row of that type was built from. Runs
     * {@see assignType()} — the very walk {@see parseList()} ran — so the two
     * skip the same elements and the ordinals stay in step.
     *
     * @param array<string, FieldMapping> $configured
     * @return array<string, list<RemoteItem>>
     */
    protected function listElementsByType(
        FieldContext $context,
        MatrixBlockSource $source,
        array $configured,
    ): array {
        if ($context->mapping->node === null) {
            return [];
        }

        $grouped = [];

        foreach ($context->item->each($context->mapping->node) ?? [] as $element) {
            $assigned = $this->assignType($context, $source, $configured, $element);

            if ($assigned !== null) {
                $grouped[$assigned['handle']][] = $assigned['item'];
            }
        }

        return $grouped;
    }

    /**
     * One block type's per-block value lists read from ITS elements — the
     * value the drill-down's Feed column reads back.
     * Positional by construction: one entry per element, nulls included, so a
     * child absent from one block keeps the later ones where they are.
     *
     * @param list<RemoteItem> $elements
     * @return array{native: array<string, list<mixed>>, fields: array<string, list<mixed>>}
     */
    protected function elementLists(FieldMapping $typeMapping, array $elements): array
    {
        $lists = ['native' => [], 'fields' => []];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $lists['native'][$sub->handle] = array_map(
                static fn(RemoteItem $element): mixed => $sub->resolve($element),
                $elements,
            );
        }

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $lists['fields'][$sub->handle] = array_map(
                static fn(RemoteItem $element): mixed => $sub->resolve($element),
                $elements,
            );
        }

        return $lists;
    }

    /**
     * One block's slice of its type's value lists: each mapped handle's feed
     * value at this block's ordinal, null where the list doesn't reach that far
     * (the per-index missing value).
     *
     * @param array{native: array<string, list<mixed>>, fields: array<string, list<mixed>>} $lists
     * @return array{native: array<string, mixed>, fields: array<string, mixed>}
     */
    protected function rawSlice(array $lists, int $ordinal): array
    {
        $slice = ['native' => [], 'fields' => []];

        foreach ($lists as $channel => $handles) {
            foreach ($handles as $handle => $values) {
                $slice[$channel][$handle] = $values[$ordinal] ?? null;
            }
        }

        return $slice;
    }

    /**
     * The mapped rows of one incoming block — the type's active NATIVE
     * sub-mappings first, then its custom ones: the order
     * {@see buildBlock()} fills a row in.
     *
     * `$raw` is this block's slice of the per-type value lists
     * ({@see rawSlice()}). A handle the row doesn't carry AND the slice has no
     * value for at this index is the per-index missing value — the case
     * {@see buildBlock()} leaves the key off the row for — so it reports as
     * unaddressed rather than as a bare null, and never as changed.
     *
     * @param array<string, mixed> $row
     * @param array{native: array<string, mixed>, fields: array<string, mixed>} $raw
     * @param ?object $partner the current block this row compares against
     * @param array<string, ?Field> $leaves
     * @return list<MappingResult>
     */
    protected function incomingChildRows(
        FieldMapping $typeMapping,
        array $row,
        array $raw,
        ?object $partner,
        ChildAction $action,
        array $leaves,
    ): array {
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $results = [];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $rawValue = $raw['native'][$sub->handle] ?? null;
            $parsed = $row[$sub->handle] ?? null;
            $currentValue = $partner !== null ? ($partner->{$sub->handle} ?? null) : null;
            $unaddressed = ! array_key_exists($sub->handle, $row) && $rawValue === null;

            $results[] = new MappingResult(
                handle: $sub->handle,
                node: $sub->node,
                default: $sub->default,
                native: true,
                rawValue: $rawValue,
                parsedValue: $parsed,
                currentValue: $currentValue,
                changed: ! $unaddressed && $this->childValueChanged($action, $partner, null, $parsed, $currentValue),
                unaddressed: $unaddressed,
            );
        }

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $rawValue = $raw['fields'][$sub->handle] ?? null;
            $parsed = $fields[$sub->handle] ?? null;
            $currentValue = $partner !== null ? $this->currentLeafValue($partner, $sub->handle) : null;
            $unaddressed = ! array_key_exists($sub->handle, $fields) && $rawValue === null;
            $leaf = $leaves[$sub->handle] ?? null;

            $results[] = new MappingResult(
                handle: $sub->handle,
                node: $sub->node,
                default: $sub->default,
                native: false,
                rawValue: $rawValue,
                parsedValue: $parsed,
                currentValue: $currentValue,
                changed: ! $unaddressed && $this->childValueChanged($action, $partner, $leaf, $parsed, $currentValue),
                unaddressed: $unaddressed,
            );
        }

        return $results;
    }

    /**
     * The mapped rows of a block the replace drops: the same native-then-custom
     * walk, but there is no feed side to show — raw and parsed stay null and
     * `changed` stays unevaluated. A block of a type the feed doesn't configure
     * has no mapped handles at all, so its child shows no rows.
     *
     * @return list<MappingResult>
     */
    protected function removedChildRows(FieldMapping $typeMapping, object $block): array
    {
        $results = [];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $results[] = new MappingResult(
                handle: $sub->handle,
                node: $sub->node,
                default: $sub->default,
                native: true,
                rawValue: null,
                currentValue: $block->{$sub->handle} ?? null,
                changed: null,
            );
        }

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $results[] = new MappingResult(
                handle: $sub->handle,
                node: $sub->node,
                default: $sub->default,
                native: false,
                rawValue: null,
                currentValue: $this->currentLeafValue($block, $sub->handle),
                changed: null,
            );
        }

        return $results;
    }

    /**
     * Whether one incoming row's leaf differs from its partner block's. An
     * UNCHANGED child was fingerprint-identical, so nothing on it can have
     * changed; a paired one compares through the strategy that owns the leaf (a
     * native passes none and lands on the shared normaliser); an unpaired one has
     * nothing to compare against, so any value it carries is new.
     */
    protected function childValueChanged(
        ChildAction $action,
        ?object $partner,
        ?Field $leaf,
        mixed $parsed,
        mixed $current,
    ): bool {
        if ($action === ChildAction::UNCHANGED) {
            return false;
        }

        if ($partner === null) {
            return $parsed !== null;
        }

        return $this->leafNormalize($leaf, $parsed) !== $this->leafNormalize($leaf, $current);
    }

    /**
     * One current block's stored value for a mapped custom handle, read the way
     * {@see currentFingerprint()} reads it.
     */
    protected function currentLeafValue(object $block, string $handle): mixed
    {
        return $block->getSerializedFieldValues([$handle])[$handle] ?? null;
    }

    /**
     * The layout carrier for one incoming block's rows — the same throwaway block
     * {@see parse()} coerces that block's values through. Presentation only, so a
     * type that no longer builds degrades to no carrier instead of throwing.
     */
    protected function labelBlock(FieldContext $context, string $typeHandle): ?ElementInterface
    {
        try {
            return $this->blockElement($context, $typeHandle);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One incoming block's label — the block's own title, never its type's name.
     * A mapped native `title` sub-mapping puts the incoming title straight on the
     * row ({@see buildBlock()}), so that wins; without one, the PARTNER
     * block's stored title still names the very block the reader is looking at,
     * and survives a feed that doesn't map titles at all.
     *
     * Null when neither side has one — the drill-down then labels the child by
     * its ordinal ({@see ChildResult::$title}).
     *
     * @param array<string, mixed> $row
     * @param ?object $partner the current block this row compares against
     */
    protected function childTitle(array $row, ?object $partner): ?string
    {
        return $this->nonEmptyTitle($row['title'] ?? null)
            ?? ($partner !== null ? $this->blockTitle($partner) : null);
    }

    /**
     * One current block's own title, or null when it holds none.
     */
    protected function blockTitle(object $block): ?string
    {
        return $this->nonEmptyTitle($block->title ?? null);
    }

    /**
     * A title only where there is one to show: a non-empty string, else null.
     */
    protected function nonEmptyTitle(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The navigable identity behind a current block, narrowed for the slots that
     * type it as an element: a current block travels as `object` through this
     * class, for the reason {@see currentFingerprint()} gives, so a block that
     * can't be narrowed simply carries no identity.
     */
    protected function blockIdentity(?object $block): ?ElementInterface
    {
        return $block instanceof ElementInterface ? $block : null;
    }

    /**
     * The child strategy behind each mapped custom handle of one block type,
     * keyed by handle in mapped order — one normaliser per fingerprint leaf.
     * Resolved off a throwaway block of the type, exactly as {@see parse()}
     * resolves the child fields it coerces values through.
     *
     * A handle the type's layout doesn't expose — or a type that no longer
     * resolves at all — maps to null. Unlike {@see parse()}, change detection
     * never throws over that: {@see leafNormalize()} falls back and the
     * comparison still runs.
     *
     * @param list<string> $customHandles
     * @return array<string, ?Field>
     */
    protected function childLeaves(FieldContext $context, string $typeHandle, array $customHandles): array
    {
        $layout = $this->blockElement($context, $typeHandle)?->getFieldLayout();

        $leaves = [];

        foreach ($customHandles as $handle) {
            $childCraftField = $layout?->getFieldByHandle($handle);
            $leaves[$handle] = $childCraftField !== null ? $this->childStrategy($context, $childCraftField) : null;
        }

        return $leaves;
    }

    /**
     * Normalise one fingerprint leaf through the strategy that owns its field
     * type, so the stored and the incoming side of the same value reduce to the
     * same form. A date leaf is the case that needs it: Craft hands the stored
     * side back serialized as a string while the incoming side is a DateTime, and
     * only {@see Date::normalize()} knows how to close that gap. Reaching a
     * sibling strategy's protected normalize() is legal — it's declared on the
     * shared {@see Field} base, which puts this class in its scope.
     */
    protected function leafNormalize(?Field $leaf, mixed $value): mixed
    {
        return $leaf !== null ? $leaf->normalize($value) : $this->normalize($value);
    }

    /**
     * Coerce one block's raw child value through the child field's own strategy
     * so per-type options (match, truthy, format, …) apply. The
     * synthetic single-value item makes the child's own resolve() yield exactly
     * this block's value; a node-less (useDefault-only) child is item-
     * independent and reuses the parent item.
     *
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    /**
     * The block-type descriptors declared on the Matrix field, in declared
     * order. Extracted — like {@see blockTypeHandles()} and
     * {@see blockElement()} — so tests can stub block-type discovery without
     * booting Craft.
     *
     * @return list<array{handle: string, name: string, layout: ?FieldLayout, hasTitleField: bool}>
     */
    protected function blockTypeDescriptors(CraftFieldInterface $field): array
    {
        return Compat::matrixBlockTypes($field);
    }

    /**
     * The block-type handles declared on the Matrix field, in declared order.
     * Extracted so tests can stub block-type discovery without booting Craft.
     *
     * @return list<string>
     */
    protected function blockTypeHandles(FieldContext $context): array
    {
        return array_map(
            static fn(array $blockType): string => $blockType['handle'],
            $this->blockTypeDescriptors($context->craftField),
        );
    }

    /**
     * A throwaway block element of the given type, whose field layout carries
     * the child craft fields. Extracted so tests can supply a fake layout
     * without booting Craft.
     */
    protected function blockElement(FieldContext $context, string $typeHandle): ?ElementInterface
    {
        return Compat::newMatrixBlock($context->craftField, $typeHandle);
    }

    /**
     * Every active child (custom + native) of one block type's sub-mapping
     * tree — used by {@see addressed()} to test whether the feed speaks to the
     * type at all.
     *
     * @return list<FieldMapping>
     */
    protected function activeChildren(FieldMapping $typeMapping): array
    {
        return array_merge(
            $this->activeSubMappings($typeMapping),
            $this->activeNativeSubMappings($typeMapping),
        );
    }

    /**
     * Active custom sub-mappings for one block type's tree.
     *
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $typeMapping): array
    {
        return $this->filterActive($typeMapping->subMappings());
    }

    /**
     * Active native sub-mappings (title/slug) for one block type's tree.
     *
     * @return list<FieldMapping>
     */
    protected function activeNativeSubMappings(FieldMapping $typeMapping): array
    {
        return $this->filterActive($typeMapping->nativeSubMappings());
    }

    /**
     * Active custom sub-mapping handles for one block type, keyed by handle
     * (order-preserving set).
     *
     * @return array<string, true>
     */
    protected function activeCustomHandles(FieldMapping $typeMapping): array
    {
        $handles = [];

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $handles[$sub->handle] = true;
        }

        return $handles;
    }

    /**
     * Active native sub-mapping handles for one block type, keyed by handle.
     *
     * @return array<string, true>
     */
    protected function activeNativeHandles(FieldMapping $typeMapping): array
    {
        $handles = [];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $handles[$sub->handle] = true;
        }

        return $handles;
    }
}
