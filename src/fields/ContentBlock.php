<?php

namespace GlueAgency\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\ContentBlock as CraftContentBlockField;
use craft\models\FieldLayout;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;

/**
 * Mapping strategy for Craft 5's ContentBlock field: one nested element holding
 * one field layout's worth of fields — no block types, no repetition. It sits
 * between {@see Matrix} (which fans out over block types) and {@see Table}
 * (which zips columns into rows): one implicit record, so its sub-mappings write
 * the mapping's flat `fields` channel like a Table's columns do.
 *
 * Two things made the DefaultField fallback wrong for it, rather than merely
 * crude:
 *
 * 1. It wrote NOTHING, silently. Craft's own
 *    {@see \craft\fields\ContentBlock::normalizeValue()} only reads an array
 *    shaped `['fields' => [handle => value]]` and ignores anything else without
 *    complaint, so a mapping that resolved to a flat handle map saved cleanly
 *    and stored nothing. Hence {@see parse()} returning that envelope — the
 *    shape Craft consumes, built here rather than demanded of the feed.
 * 2. It was ALWAYS changed. The stored value is a nested element, which
 *    {@see Comparable::of()} reduces to its id, against a JSON blob for the
 *    incoming array — never equal, so every run re-saved the owner and cut a
 *    revision. {@see valueDiffers()} compares the nested element's own field
 *    values instead, leaf by leaf.
 *
 * Values are coerced through each child field's own strategy, so a date inside a
 * content block parses like a date anywhere else.
 */
class ContentBlock extends Field
{
    public static function craftFieldClass(): ?string
    {
        return CraftContentBlockField::class;
    }

    public function childrenKind(): ?string
    {
        return 'fields';
    }

    /**
     * One card of rows — the block's own layout, which is a single fixed layout
     * rather than one per type. Each row carries the default editor its own field
     * declares ({@see SchemaBuilder::fieldRow()}), so a relation inside a content
     * block offers a picker.
     */
    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        $subFields = SchemaBuilder::make();

        foreach ($this->layoutFields($field) as $childField) {
            $subFields->fieldRow($this->fieldEditorFor($childField), [
                'handle' => $childField->handle,
                'label'  => $childField->name,
            ]);
        }

        $rows = $subFields->toArray();

        if (! $rows) {
            return SchemaBuilder::make()
                ->note(['text' => Craft::t('influx', 'This content block has no mappable fields yet.')]);
        }

        return SchemaBuilder::make()->subFields([
            'label'     => Craft::t('influx', 'Fields'),
            'subFields' => $rows,
        ]);
    }

    /**
     * The row's value derives entirely from its sub-mappings — there is no source
     * node or default on the row itself, the same flag {@see Matrix} and
     * {@see Table} declare.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [
            'subfieldsOnly' => true,
        ];
    }

    /**
     * A node-less row is addressed through its sub-mappings, never its own
     * (absent) node — so it's addressed when ANY active one is addressed for this
     * item. A block whose fields are all unaddressed leaves the field untouched.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the `['fields' => …]` envelope Craft consumes, one key per mapped
     * handle the layout still declares.
     *
     * A configured handle the layout no longer has is skipped silently, the way
     * {@see Table} skips a removed column: the mapping outlived the field, which
     * is not a structural error — Craft's own consumption would swallow it
     * anyway ({@see \craft\fields\ContentBlock} catches InvalidFieldException per
     * handle).
     *
     * An all-empty result is still returned as the envelope rather than null:
     * {@see addressed()} was true, so the feed is authoritative even when every
     * field resolved to nothing, and Craft needs the envelope to clear them.
     *
     * @return array{fields: array<string, mixed>}
     */
    public function parse(FieldContext $context): mixed
    {
        $layoutFields = $this->layoutFieldsByHandle($context->craftField);
        $blockElement = $this->blockElement($context);
        $fields = [];

        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            $childField = $layoutFields[$sub->handle] ?? null;

            if ($childField === null) {
                continue;
            }

            $fields[$sub->handle] = $this->coerceChildValue(
                $context,
                $blockElement,
                $sub,
                $childField,
                $sub->resolve($context->item),
            );
        }

        return ['fields' => $fields];
    }

    /**
     * Compare the nested element's own field values, per mapped handle, rather
     * than the element against an array — the comparison that made every sync
     * re-save the owner.
     *
     * Only the MAPPED handles are compared, for the same reason Table restricts
     * its fingerprint to mapped columns: a field the feed doesn't address isn't
     * this mapping's business, and counting it would make an untouched block look
     * changed on its own. Each leaf is reduced through the strategy that owns it,
     * so a stored `DateTime` and the feed's ISO string agree.
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $incomingFields = is_array($incoming) ? ($incoming['fields'] ?? []) : [];
        $layoutFields = $this->layoutFieldsByHandle($context->craftField);

        foreach ($incomingFields as $handle => $value) {
            $leaf = isset($layoutFields[$handle]) ? $context->strategyFor($layoutFields[$handle]) : null;

            $storedPrint = $this->leafPrint($leaf, $this->currentLeafValue($current, $handle));
            $incomingPrint = $this->leafPrint($leaf, $value);

            if ($storedPrint !== $incomingPrint) {
                return true;
            }
        }

        return false;
    }

    /**
     * One leaf's comparable form: the owning strategy's own normalisation where
     * there is one, the shared normaliser otherwise — mirrors
     * {@see Matrix::leafNormalize()}.
     */
    protected function leafPrint(?Field $leaf, mixed $value): mixed
    {
        return $leaf !== null ? $leaf->normalize($value) : Comparable::of($value);
    }

    /**
     * The stored value of one handle on the nested element, read the way Craft
     * serializes it. Null when nothing is nested yet — a block that doesn't exist
     * holds no values, so every incoming value is new.
     */
    protected function currentLeafValue(mixed $current, string $handle): mixed
    {
        if (! $current instanceof ElementInterface) {
            return null;
        }

        return $current->getSerializedFieldValues([$handle])[$handle] ?? null;
    }

    /**
     * Coerce one raw child value through the child field's own strategy so its
     * per-field options (match, truthy, format, …) apply. The synthetic
     * single-value item makes the child's own resolve() yield exactly this value;
     * a node-less (useDefault-only) child is item-independent and reuses the
     * parent item. The same seam {@see Matrix::coerceChildValue()} uses.
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

        return $context->strategyFor($childCraftField)->parse($childContext);
    }

    /**
     * The layout carrier a child value is coerced against. The nested element
     * Craft would build for this field, or the owner as a stand-in: a child
     * strategy reads the carrier for its own field's settings, and every one of
     * those lives on the child field itself — so a field whose block can't be
     * built (no owner id yet) still parses.
     */
    protected function blockElement(FieldContext $context): ElementInterface
    {
        return $context->element;
    }

    /**
     * The block layout's own custom fields, in layout order.
     *
     * @return list<CraftFieldInterface>
     */
    protected function layoutFields(?CraftFieldInterface $field): array
    {
        $layout = $this->blockLayout($field);

        return $layout !== null ? array_values($layout->getCustomFields()) : [];
    }

    /**
     * @return array<string, CraftFieldInterface>
     */
    protected function layoutFieldsByHandle(?CraftFieldInterface $field): array
    {
        $byHandle = [];

        foreach ($this->layoutFields($field) as $childField) {
            $byHandle[$childField->handle] = $childField;
        }

        return $byHandle;
    }

    /**
     * The field's own layout. Extracted so a schema spec can stub it without
     * booting Craft, the way the relational flavours stub their source layouts.
     */
    protected function blockLayout(?CraftFieldInterface $field): ?FieldLayout
    {
        if (! $field instanceof CraftContentBlockField) {
            return null;
        }

        return $field->getFieldLayout();
    }

    /**
     * The sub-mappings that actually carry something — the same active-only rule
     * the other sub-mapping strategies apply.
     *
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $mapping): array
    {
        $active = [];

        foreach ($mapping->subMappings() as $sub) {
            if ($sub->isActive()) {
                $active[] = $sub;
            }
        }

        return $active;
    }
}
