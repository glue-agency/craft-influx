<?php

namespace GlueAgency\Influx\fields;

use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Per-Craft-field-type mapping strategy. One concrete subclass per `craft\fields\*`
 * class whose mapping behaviour genuinely diverges from the default; everything
 * else falls through to {@see DefaultField}.
 *
 * Strategies are stateless shared singletons (see
 * {@see \GlueAgency\Influx\services\FieldsService}): everything a call needs travels
 * in an immutable {@see FieldContext}, so the sub-mapping recursion can safely
 * re-enter the same instance.
 *
 * Lifecycle, driven by {@see \GlueAgency\Influx\sync\MappingApplier}:
 *
 *   $value = $strategy->parse($context);
 *   if ($value !== null && $strategy->hasChanged($context, $value)) {
 *       $strategy->apply($context, $value);
 *   }
 *
 * `parse()` is the one method subclasses have to implement; everything else
 * has a sensible default in this base.
 */
abstract class Field
{
    /**
     * FQCN of the Craft field class this strategy handles. Return `null` to
     * register as the generic fallback (only {@see DefaultField} should).
     *
     * Subclasses may also point at a base class (e.g. `BaseOptionsField`)
     * to cover a whole family — {@see \GlueAgency\Influx\services\FieldsService}
     * walks the parent chain on lookup.
     */
    public static function craftFieldClass(): ?string
    {
        return null;
    }

    /**
     * Resolve the remote item + per-field mapping into the value the element
     * field should hold.
     *
     * Contract:
     *   - return null — "no data for this mapping, leave the field untouched";
     *   - throw       — present-but-malformed data; the applier surfaces it
     *                   as a per-mapping error.
     *
     * Strategies with side effects (creating missing relations, uploading
     * assets, writing to related elements) must honour `$context->dryRun`
     * and skip them when set — the debug inspector runs this exact code path.
     */
    abstract public function parse(FieldContext $context): mixed;

    /**
     * UI-side metadata for the mapping editor. Targets call this through
     * {@see \GlueAgency\Influx\services\FieldsService::metaFor()} when building the
     * mappable-fields list, so per-field-type UI hints (asset sub-fields,
     * dropdown options, relation element type, ...) live next to the parse
     * logic instead of in a giant if-chain on the target.
     *
     * Subclasses override when they have something to say; the default is
     * "no extras", which is correct for plain field types.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [];
    }

    /**
     * Declarative form schema for this field type's mapping-extras block —
     * a list of {@see \GlueAgency\Influx\helpers\BuilderSchema} nodes the SPA
     * renders generically. Declaring the UI next to the parse logic is what
     * keeps the Vue side free of per-field-type branches: adding a kind is
     * a single-PHP-file change.
     *
     * Default: no extras.
     */
    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        return [];
    }

    /**
     * UI strings shared by every kind of mapping-extras block — currently
     * just the show/hide toggle copy. Strategies layer their own labels on
     * top via `static::extrasLabels()` (or analogous) when assembling
     * {@see fieldMeta()}, so the Vue side reads everything from
     * `fieldMeta.labels` instead of hard-coding translations.
     *
     * @return array<string, string>
     */
    public static function commonExtrasLabels(): array
    {
        return [
            'configure'   => \Craft::t('influx', 'Configure'),
            'hideOptions' => \Craft::t('influx', 'Hide options'),
        ];
    }

    /**
     * Set the parsed value on the context's element. Default: route to
     * `setFieldValue`, which is correct for every custom field. Subclasses
     * override only when they need something more involved.
     */
    public function apply(FieldContext $context, mixed $value): bool
    {
        $context->element->setFieldValue($context->handle, $value);
        return true;
    }

    /**
     * Whether the incoming value differs from what the element currently holds.
     * The sync engine uses this to skip elements that nothing has changed for.
     */
    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        try {
            $current = $context->element->getFieldValue($context->handle);
        } catch (\Throwable) {
            return true;
        }
        return $this->normalize($current) !== $this->normalize($incoming);
    }

    // -- shared helpers ----------------------------------------------------

    /**
     * Project-config-friendly representation used to compare values for change
     * detection. Two semantically-equal values should produce the same string.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if ($value instanceof \Stringable) {
            $str = (string)$value;
            return $str === '' ? null : $str;
        }
        return json_encode($value);
    }
}
