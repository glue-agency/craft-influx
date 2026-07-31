<?php

namespace GlueAgency\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix as CraftMatrixField;
use craft\models\FieldLayout;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use Throwable;

/**
 * Mapping strategy for Craft's Matrix field. Turns a remote list into Matrix
 * blocks across ANY of the field's block types (Feed Me-style) — the mapping
 * carries a per-block-type sub-mapping tree under a `blocks` channel
 * ({@see FieldMapping::blockMappings()}), keyed by block-type handle. Each
 * entry is itself a node-less FieldMapping-shaped config
 * (`{fields: {...}, nativeFields: {...}}`) whose child node paths are ABSOLUTE
 * (resolved against the top-level item, exactly like relational sub-mappings).
 *
 * The persisted shape mirrors Feed Me's:
 *
 *   mappings[<matrixHandle>] = {
 *       blocks: {
 *           text:  { fields: { body: { node: 'content.body' } } },
 *           quote: { fields: { text: { node: 'quotes.text' } },
 *                    nativeFields: { title: { node: 'quotes.author' } } },
 *       },
 *   }
 *
 * WITHIN a single block type the semantics are unchanged from the old
 * single-type engine: only active children ({@see FieldMapping::isActive()})
 * contribute; {@see RemoteItem}'s collapsed-list semantics turn `seasons.year`
 * into the list of every season's year; blocks are built by index-zipping those
 * per-child value lists; a per-index missing value just leaves that key absent
 * on that block; each child value is coerced through its own strategy via a
 * synthetic single-value {@see RemoteItem} + {@see FieldContext::descend()}.
 *
 * ACROSS block types blocks are GROUPED BY TYPE, never interleaved: the field's
 * block types are walked in their DECLARED order ({@see blockTypeHandles()}),
 * each configured type emits all of its zipped blocks, and the `newN` counter
 * runs continuously across every type (new1, new2, … global) so block output
 * order is deterministic.
 *
 * The parent Matrix mapping row itself has NO node: its value comes entirely
 * from the per-type sub-mappings. Extends {@see Field} directly (NOT
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
 * Known v1 limitation — array-valued child nodes mis-fan: a child node that
 * resolves to a flat array for ONE block is indistinguishable from per-block
 * scalar values (both arrive as a list here), so it would be spread across
 * blocks rather than stored as one block's array value. v1 targets
 * scalar-per-block child nodes; array-valued single-block children are out of
 * scope.
 */
class Matrix extends Field
{
    /**
     * Cap on the children one mapping row emits. A runaway feed — a node that
     * fans out into thousands of blocks — would otherwise balloon the debug
     * inspector's payload and the log rows a run stores, for no diagnostic gain:
     * the first hundred already show what the mapping does. The cap is PER
     * MAPPING ROW, so every Matrix row of a link gets its own hundred.
     */
    protected const CHILD_RESULT_LIMIT = 100;

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
     * the custom fields, in the same order {@see appendTypeBlocks()} fills a
     * block in. Rows carry an optional `channel` key that routes where the SPA
     * writes them: `nativeFields` for the title, ABSENT for a custom field —
     * absent means the `fields` channel, which is the stored shape that predates
     * the key.
     */
    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        $blockTypes = $this->blockTypeDescriptors($field);

        if (! $blockTypes) {
            return SchemaBuilder::make()
                ->note(['text' => Craft::t('influx', 'This Matrix field has no block types to map yet.')]);
        }

        $builder = SchemaBuilder::make();

        foreach ($blockTypes as $blockType) {
            $subFields = SchemaBuilder::make();
            $layout = $blockType['layout'];

            if ($blockType['hasTitleField']) {
                $subFields->text([
                    'handle'  => 'title',
                    'label'   => $this->titleLabel($layout),
                    'channel' => 'nativeFields',
                ]);
            }

            foreach ($layout !== null ? $layout->getCustomFields() : [] as $customField) {
                $subFields->text([
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
     * The Matrix row's value derives entirely from its sub-mappings — there is
     * no source node or default on the row itself. `subfieldsOnly` tells the
     * SPA's MappingRow to render neither control; any other strategy whose
     * value comes solely from its extras can declare the same flag.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [
            'subfieldsOnly' => true,
        ];
    }

    /**
     * A node-less Matrix row is addressed via its per-type sub-mappings, never
     * its own (absent) node — so it's addressed when ANY active sub-mapping
     * (custom `fields` or `nativeFields`), in ANY configured block-type tree,
     * is addressed for this item. A row whose every configured type has only
     * inactive or entirely-unaddressed children leaves the field untouched.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($context->mapping->blockMappings() as $typeMapping) {
            foreach ($this->activeChildren($typeMapping) as $sub) {
                if ($sub->addressedBy($context->item)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build the flat serialized Matrix value from the mapping's per-block-type
     * sub-mapping trees. Block types are walked in the field's declared order;
     * types with no configured entry are skipped, so output blocks are grouped
     * by type in field order with a continuous `newN` counter. An empty result
     * is still returned as an explicit clear rather than null: {@see addressed()}
     * was true, so the feed is authoritative even when every child resolved to
     * null.
     *
     * @throws MappingValueException when a configured block-type handle is
     * unknown for the field, or a throwaway block can't be built
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

        $blocks = [];
        $index = 0;

        foreach ($fieldHandles as $typeHandle) {
            if (! isset($configured[$typeHandle])) {
                continue;
            }

            $index = $this->appendTypeBlocks($context, $typeHandle, $configured[$typeHandle], $blocks, $index);
        }

        return $blocks;
    }

    /**
     * Zip one block type's active children into blocks, appending them to
     * `$blocks` with sequential `new{N}` keys continued from `$index`. Returns
     * the updated index so the caller keeps the counter continuous across types.
     *
     * A child resolving to null contributes no per-block values, and a child
     * handle that isn't on the block type's own layout is skipped silently.
     *
     * @param array<string, mixed> $blocks accumulator, mutated in place
     * @throws MappingValueException when the throwaway block can't be built
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    protected function appendTypeBlocks(
        FieldContext $context,
        string $typeHandle,
        FieldMapping $typeMapping,
        array &$blocks,
        int $index,
    ): int {
        $customLists = [];
        $customSubs = [];

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $resolved = $sub->resolve($context->item);

            if ($resolved === null) {
                continue;
            }

            $customLists[$sub->handle] = $this->valueList($resolved);
            $customSubs[$sub->handle] = $sub;
        }

        $nativeLists = [];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $resolved = $sub->resolve($context->item);

            if ($resolved === null) {
                continue;
            }

            $nativeLists[$sub->handle] = $this->valueList($resolved);
        }

        // array_values on BOTH: a custom handle can collide with a native one,
        // and a merge keyed by handle would drop one of the two lists.
        $blockCount = $this->maxLength([...array_values($customLists), ...array_values($nativeLists)]);

        if ($blockCount === 0) {
            return $index;
        }

        $blockElement = $this->blockElement($context, $typeHandle);

        if ($blockElement === null) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' could not build a block of type '{$typeHandle}'.",
            );
        }

        $layout = $blockElement->getFieldLayout();

        for ($i = 0; $i < $blockCount; $i++) {
            $row = [
                'type'    => $typeHandle,
                'enabled' => true,
            ];

            foreach ($nativeLists as $handle => $values) {
                if (array_key_exists($i, $values)) {
                    $row[$handle] = (string) $values[$i];
                }
            }

            foreach ($customLists as $handle => $values) {
                if (! array_key_exists($i, $values)) {
                    continue;
                }

                $childCraftField = $layout?->getFieldByHandle($handle);

                if ($childCraftField === null) {
                    continue;
                }

                $row['fields'][$handle] = $this->coerceChildValue(
                    $context,
                    $blockElement,
                    $customSubs[$handle],
                    $childCraftField,
                    $values[$i],
                );
            }

            $index++;
            $blocks['new' . $index] = $row;
        }

        return $index;
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
     * null contributes no per-block value, so {@see appendTypeBlocks()} leaves it
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
                    $lists[$type] = $this->resolvedLists($context, $typeMapping);
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
     * Pairing leans on the block-ordering guarantees the class docblock states:
     * blocks are grouped by type, so the nth non-removed child OF A TYPE is the
     * nth saved block of that type. A REMOVED child stands for a block the
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
     * The index of the first not-yet-consumed entry equal to `$needle` — the one
     * greedy step both pairing passes take, over fingerprints and then over type
     * handles.
     *
     * @param list<mixed> $values
     * @param array<int, true> $consumed
     */
    protected function firstUnconsumed(array $values, array $consumed, mixed $needle): ?int
    {
        foreach ($values as $index => $value) {
            if (! isset($consumed[$index]) && $value === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * One block type's active sub-mappings resolved to per-block value lists —
     * the same resolve-and-{@see valueList()} step {@see appendTypeBlocks()} zips
     * into blocks, so indexing a list by a block's ordinal among the incoming
     * rows of its type recovers the feed value that block was built from. Called
     * once per type per collection (the caller memoizes), because one resolve
     * walks the whole item and the same list serves every block of the type.
     *
     * @return array{native: array<string, list<mixed>>, fields: array<string, list<mixed>>}
     */
    protected function resolvedLists(FieldContext $context, FieldMapping $typeMapping): array
    {
        $lists = ['native' => [], 'fields' => []];

        foreach ($this->activeNativeSubMappings($typeMapping) as $sub) {
            $lists['native'][$sub->handle] = $this->valueList($sub->resolve($context->item));
        }

        foreach ($this->activeSubMappings($typeMapping) as $sub) {
            $lists['fields'][$sub->handle] = $this->valueList($sub->resolve($context->item));
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
     * {@see appendTypeBlocks()} fills a row in.
     *
     * `$raw` is this block's slice of the per-type value lists
     * ({@see rawSlice()}). A handle the row doesn't carry AND the slice has no
     * value for at this index is the per-index missing value — the case
     * {@see appendTypeBlocks()} leaves the key off the row for — so it reports as
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
     * The action string a child carries: the hypothetical label on a dry run, the
     * committed value on a real one ({@see ChildAction::dryRunLabel()}).
     */
    protected function childActionLabel(FieldContext $context, ChildAction $action): string
    {
        return $context->dryRun ? $action->dryRunLabel() : $action->value;
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
     * row ({@see appendTypeBlocks()}), so that wins; without one, the PARTNER
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
    protected function coerceChildValue(
        FieldContext $context,
        ElementInterface $blockElement,
        FieldMapping $sub,
        CraftFieldInterface $childCraftField,
        mixed $value,
    ): mixed {
        $childItem = $sub->node !== null
            ? new RemoteItem(Hash::insert([], $sub->node, $value))
            : $context->item;

        $childContext = $context->descend($blockElement, $sub, $childCraftField, $childItem);

        return $this->childStrategy($context, $childCraftField)->parse($childContext);
    }

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
     * Resolve the mapping strategy for a child craft field, through the seam the
     * context carries ({@see FieldContext::strategyFor()}) rather than the plugin
     * singleton — a field strategy has no business reaching a global mid-walk.
     * Still extracted so tests can record the {@see FieldContext} a child
     * receives and return a marker.
     */
    protected function childStrategy(FieldContext $context, CraftFieldInterface $childCraftField): Field
    {
        return $context->strategyFor($childCraftField);
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
     * @param iterable<FieldMapping> $mappings
     * @return list<FieldMapping>
     */
    protected function filterActive(iterable $mappings): array
    {
        $active = [];

        foreach ($mappings as $mapping) {
            if ($mapping->isActive()) {
                $active[] = $mapping;
            }
        }

        return $active;
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
