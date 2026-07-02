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
 * blocks of a single, fixed block type — one child-mapping tree ({@see fields}
 * / {@see nativeFields}) shared across every block, index-zipped from the
 * per-child value lists the parent item resolves.
 *
 * The parent Matrix mapping row itself has NO node: its value comes entirely
 * from its sub-mappings, whose node paths are ABSOLUTE (resolved against the
 * top-level item, exactly like relational sub-mappings). {@see RemoteItem}'s
 * collapsed-list semantics turn `seasons.year` into the list of every season's
 * year; blocks are built by index-zipping those per-child lists. Only
 * sub-mappings with an actual mapping ({@see FieldMapping::isActive()})
 * contribute — a per-index missing value just leaves that key absent on that
 * block.
 *
 * Extends {@see Field} directly (NOT {@see DefaultField}, NOT
 * {@see RelationalField}): it neither writes related ids nor persists
 * sub-elements — it builds Craft's flat serialized Matrix value shape
 * (`['new1' => ['type' => …, 'enabled' => true, 'fields' => […]]]`), which the
 * inherited {@see apply()} hands to `setFieldValue`. Blocks are only ever
 * persisted by the OWNER element's save, so {@see parse()} is dry-run-safe by
 * construction: it creates nothing, saves nothing, and coerces child values
 * through their own strategies purely in memory.
 *
 * Sync semantics are full-replace: every incoming block gets a fresh `newN`
 * key, so a sync rebuilds the field's blocks from the feed.
 * {@see valueDiffers()} compares a mapped-keys-only fingerprint so an
 * unchanged feed never triggers a destructive replace.
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

        $typeOptions = [];

        foreach ($blockTypes as $blockType) {
            $typeOptions[] = [
                'value' => $blockType['handle'],
                'label' => $blockType['name'],
            ];
        }

        $schema = [
            BuilderSchema::select('blockType', Craft::t('influx', 'Block type'), $typeOptions, [
                'default' => $blockTypes[0]['handle'],
            ]),
        ];

        // One sub-field card per block type, each gated to the selected
        // `blockType` option via showIf — the SPA only ever shows the card
        // matching the block type the blocks will be built as.
        foreach ($blockTypes as $blockType) {
            $subFields = [];
            $layout = $blockType['layout'];

            foreach ($layout !== null ? $layout->getCustomFields() : [] as $customField) {
                $subFields[] = BuilderSchema::text(
                    $customField->handle,
                    $customField->name . ' (' . $customField->handle . ')',
                );
            }

            $schema[] = BuilderSchema::matrixFields(
                Craft::t('influx', 'Block fields'),
                $subFields,
                [
                    'blockType' => $blockType['handle'],
                    'showIf'    => [['handle' => 'blockType', 'equals' => $blockType['handle']]],
                ],
            );
        }

        $schema[] = BuilderSchema::note(
            Craft::t('influx', 'Sub-field source nodes are absolute item paths (seasons.year, not year): each resolves to one value per block, and blocks are index-zipped from those lists. Only mapped sub-fields are written to the blocks.'),
        );

        return $schema;
    }

    /**
     * A node-less Matrix row is addressed via its sub-mappings, never its own
     * (absent) node — so it's addressed when ANY active sub-mapping (custom
     * `fields` or `nativeFields`) is addressed for this item. An inactive-only
     * or entirely-unaddressed row leaves the field untouched.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the flat serialized Matrix value from the mapping's sub-mappings.
     *
     * @throws MappingValueException when the configured block type is missing
     * or unknown for the field
     * @throws \GlueAgency\Influx\exceptions\MappingDepthException past MAX_DEPTH
     */
    public function parse(FieldContext $context): mixed
    {
        $typeHandle = (string) $context->mapping->option('blockType', '');

        if ($typeHandle === '' || ! in_array($typeHandle, $this->blockTypeHandles($context), true)) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' has an unknown block type '{$typeHandle}'.",
            );
        }

        // Collect each active child's per-block value list, keyed by handle,
        // split into the native (title/slug) and custom channels. resolve()
        // reads the child's absolute node against the top-level item; null
        // means the feed doesn't address that child, so it's skipped entirely.
        $customLists = [];
        $customSubs = [];

        foreach ($this->activeSubMappings($context) as $sub) {
            $resolved = $sub->resolve($context->item);

            if ($resolved === null) {
                continue;
            }

            $customLists[$sub->handle] = $this->valueList($resolved);
            $customSubs[$sub->handle] = $sub;
        }

        $nativeLists = [];

        foreach ($this->activeNativeSubMappings($context) as $sub) {
            $resolved = $sub->resolve($context->item);

            if ($resolved === null) {
                continue;
            }

            $nativeLists[$sub->handle] = $this->valueList($resolved);
        }

        // addressed() was true, so the feed spoke — but every contributing
        // child resolved to null. The feed is authoritative: return an explicit
        // clear rather than leaving stale blocks.
        $blockCount = $this->maxLength($customLists, $nativeLists);

        if ($blockCount === 0) {
            return [];
        }

        $blockElement = $this->blockElement($context, $typeHandle);

        if ($blockElement === null) {
            throw new MappingValueException(
                "Matrix mapping '{$context->handle}' could not build a block of type '{$typeHandle}'.",
            );
        }

        $layout = $blockElement->getFieldLayout();

        $blocks = [];

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

            $blocks['new' . ($i + 1)] = $row;
        }

        return $blocks;
    }

    /**
     * Full-replace fingerprint comparison, restricted to the mapped child
     * handles so an unchanged feed never triggers a destructive rebuild.
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

        $incomingBlocks = is_array($incoming) ? array_values($incoming) : [];

        $customHandles = array_keys($this->activeCustomHandles($context));
        $nativeHandles = array_keys($this->activeNativeHandles($context));

        $incomingPrint = [];

        foreach ($incomingBlocks as $row) {
            $incomingPrint[] = $this->incomingFingerprint($row, $customHandles, $nativeHandles);
        }

        $currentPrint = [];

        foreach ($current->all() as $block) {
            $currentPrint[] = $this->currentFingerprint($block, $customHandles, $nativeHandles);
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
     * The block-type handles declared on the Matrix field. Extracted so tests
     * can stub block-type discovery without booting Craft.
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
     * Active custom sub-mappings for this Matrix row.
     *
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldContext $context): array
    {
        return $this->filterActive($context->mapping->subMappings());
    }

    /**
     * Active native sub-mappings (title/slug) for this Matrix row.
     *
     * @return list<FieldMapping>
     */
    protected function activeNativeSubMappings(FieldContext $context): array
    {
        return $this->filterActive($context->mapping->nativeSubMappings());
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
     * Active custom sub-mapping handles, keyed by handle (order-preserving set).
     *
     * @return array<string, true>
     */
    protected function activeCustomHandles(FieldContext $context): array
    {
        $handles = [];

        foreach ($this->activeSubMappings($context) as $sub) {
            $handles[$sub->handle] = true;
        }

        return $handles;
    }

    /**
     * Active native sub-mapping handles, keyed by handle.
     *
     * @return array<string, true>
     */
    protected function activeNativeHandles(FieldContext $context): array
    {
        $handles = [];

        foreach ($this->activeNativeSubMappings($context) as $sub) {
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
