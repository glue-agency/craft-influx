<?php

namespace GlueAgency\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Matrix as CraftMatrixField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;

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
 * run).
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
    public static function craftFieldClass(): ?string
    {
        return CraftMatrixField::class;
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        $blockTypes = Compat::matrixBlockTypes($field);

        if (! $blockTypes) {
            return [
                BuilderSchema::note(
                    Craft::t('influx', 'This Matrix field has no block types to map yet.'),
                ),
            ];
        }

        $schema = [];

        // One always-visible card per block type (Feed Me-style): the card
        // is labeled with the type's name, carries the type's custom fields
        // as rows, and reads/writes its own `blocks.<handle>.fields` slice
        // of the mapping. A type without custom fields still gets its card —
        // the SPA renders an empty-state hint so the full type list stays
        // visible. Row labels carry the field NAME only; the SPA renders the
        // handle separately, styled like every other sub-field row.
        foreach ($blockTypes as $blockType) {
            $subFields = [];
            $layout = $blockType['layout'];

            foreach ($layout !== null ? $layout->getCustomFields() : [] as $customField) {
                $subFields[] = BuilderSchema::text(
                    $customField->handle,
                    $customField->name,
                );
            }

            $schema[] = BuilderSchema::matrixFields(
                $blockType['name'],
                $subFields,
                ['blockType' => $blockType['handle']],
            );
        }

        return $schema;
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
     * by type in field order with a continuous `newN` counter.
     *
     * @throws MappingValueException when a configured block-type handle is
     * unknown for the field, or a throwaway block can't be built
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    public function parse(FieldContext $context): mixed
    {
        $configured = $context->mapping->blockMappings();

        // Validate every configured type handle against the field's block
        // types up front — an unknown handle is a config error, not a per-item
        // "no data" case, so it throws rather than silently emitting nothing.
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

        // Field-declared order — deterministic, grouped by type. Skip types the
        // mapping doesn't configure.
        foreach ($fieldHandles as $typeHandle) {
            if (! isset($configured[$typeHandle])) {
                continue;
            }

            $index = $this->appendTypeBlocks($context, $typeHandle, $configured[$typeHandle], $blocks, $index);
        }

        // addressed() was true, so the feed spoke — but every contributing
        // child across every configured type resolved to null. The feed is
        // authoritative: return an explicit clear rather than leaving stale
        // blocks.
        return $blocks;
    }

    /**
     * Zip one block type's active children into blocks, appending them to
     * `$blocks` with sequential `new{N}` keys continued from `$index`. Returns
     * the updated index so the caller keeps the counter continuous across types.
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
        // Collect each active child's per-block value list, keyed by handle,
        // split into the native (title/slug) and custom channels. resolve()
        // reads the child's absolute node against the top-level item; null
        // means the feed doesn't address that child, so it's skipped entirely.
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

        $blockCount = $this->maxLength($customLists, $nativeLists);

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

                // Handle isn't on the block type's layout — skip silently,
                // mirroring MappingApplier::applySubMappings().
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
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        if (! is_object($current) || ! method_exists($current, 'all')) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        // Per-type mapped handle sets, resolved once for the whole comparison.
        $customByType = [];
        $nativeByType = [];

        foreach ($context->mapping->blockMappings() as $typeHandle => $typeMapping) {
            $customByType[$typeHandle] = array_keys($this->activeCustomHandles($typeMapping));
            $nativeByType[$typeHandle] = array_keys($this->activeNativeHandles($typeMapping));
        }

        $incomingBlocks = is_array($incoming) ? array_values($incoming) : [];

        $incomingPrint = [];

        foreach ($incomingBlocks as $row) {
            $type = (string) ($row['type'] ?? '');
            $incomingPrint[] = $this->incomingFingerprint(
                $row,
                $customByType[$type] ?? [],
                $nativeByType[$type] ?? [],
            );
        }

        $currentPrint = [];

        foreach ($current->all() as $block) {
            $type = $block->getType()->handle;

            // A block whose type the feed doesn't configure fingerprints on its
            // type alone — no mapped handles to read, so it can never match an
            // incoming block, and the comparison differs.
            $currentPrint[] = $this->currentFingerprint(
                $block,
                $customByType[$type] ?? [],
                $nativeByType[$type] ?? [],
            );
        }

        return json_encode($currentPrint) !== json_encode($incomingPrint);
    }

    // -- fingerprint helpers --------------------------------------------------

    /**
     * Fingerprint one parsed incoming block row: type, native values, then the
     * ksort'd mapped custom values — every leaf normalised so it lines up with
     * the current-block fingerprint.
     *
     * @param array<string, mixed> $row
     * @param list<string> $customHandles
     * @param list<string> $nativeHandles
     * @return array<string, mixed>
     */
    protected function incomingFingerprint(array $row, array $customHandles, array $nativeHandles): array
    {
        $print = ['type' => $row['type'] ?? null];

        foreach ($nativeHandles as $handle) {
            $print['native'][$handle] = $this->normalize($row[$handle] ?? null);
        }

        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $print['fields'] = [];

        foreach ($customHandles as $handle) {
            if (array_key_exists($handle, $fields)) {
                $print['fields'][$handle] = $this->normalize($fields[$handle]);
            }
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
     * @param list<string> $customHandles
     * @param list<string> $nativeHandles
     * @return array<string, mixed>
     */
    protected function currentFingerprint(object $block, array $customHandles, array $nativeHandles): array
    {
        $print = ['type' => $block->getType()->handle];

        foreach ($nativeHandles as $handle) {
            $print['native'][$handle] = $this->normalize($block->{$handle} ?? null);
        }

        $serialized = $block->getSerializedFieldValues($customHandles);
        $print['fields'] = [];

        foreach ($customHandles as $handle) {
            if (array_key_exists($handle, $serialized)) {
                $print['fields'][$handle] = $this->normalize($serialized[$handle]);
            }
        }

        ksort($print['fields']);

        return $print;
    }

    // -- child resolution -----------------------------------------------------

    /**
     * Coerce one block's raw child value through the child field's own strategy
     * so per-type options (valueMap, truthy, match, format, …) apply. The
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

        return $this->childStrategy($childCraftField)->parse($childContext);
    }

    // -- introspection seams (overridable in tests) ---------------------------

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
            Compat::matrixBlockTypes($context->craftField),
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
     * Resolve the mapping strategy for a child craft field. Extracted so tests
     * can record the {@see FieldContext} a child receives and return a marker.
     */
    protected function childStrategy(CraftFieldInterface $childCraftField): Field
    {
        return Influx::getInstance()->fields->forCraftField($childCraftField);
    }

    // -- shared helpers -------------------------------------------------------

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

    /**
     * Normalise a resolved child value into a per-block value list. A list
     * array is one value per block; anything else (a scalar, or an assoc array
     * that is ONE block's value) becomes a single-element list.
     *
     * @return list<mixed>
     */
    protected function valueList(mixed $resolved): array
    {
        return is_array($resolved) && array_is_list($resolved) ? $resolved : [$resolved];
    }

    /**
     * The largest per-block list length across every contributing child —
     * the block count. Ragged lists yield the longest; a per-block missing
     * value just leaves that child's key absent on the trailing blocks.
     *
     * @param array<string, list<mixed>> $customLists
     * @param array<string, list<mixed>> $nativeLists
     */
    protected function maxLength(array $customLists, array $nativeLists): int
    {
        $max = 0;

        foreach (array_merge(array_values($customLists), array_values($nativeLists)) as $values) {
            $max = max($max, count($values));
        }

        return $max;
    }
}
