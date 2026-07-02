<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\sync\FieldContext;
use Stringable;
use Throwable;

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
 *   // An actively-mapped field is always written — a null/empty value clears
 *   // it (the feed is authoritative). hasChanged() only decides whether the
 *   // write counts toward the element's save-worthy "changed" flag.
 *   $changed = $strategy->hasChanged($context, $value);
 *   $strategy->apply($context, $value);
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
     * Optional extra UI metadata, merged into the payload by
     * {@see \GlueAgency\Influx\services\FieldsService::metaFor()}. The mapping
     * extras UI is declared via {@see defineExtrasSchema()} — the primary
     * contract, with labels co-located on each node — so most strategies
     * never need this. Override only to ship structured meta a schema node
     * can't express; `schema` and `labels` are reserved keys set by metaFor.
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
     * The extras block's show/hide toggle copy — the only UI strings not
     * carried on a schema node, and identical for every field kind.
     * {@see \GlueAgency\Influx\services\FieldsService::metaFor()} ships them
     * as `fieldMeta.labels` so the Vue toggle reads translated copy instead
     * of hard-coding English.
     *
     * @return array<string, string>
     */
    public static function commonExtrasLabels(): array
    {
        return [
            'configure'   => Craft::t('influx', 'Configure'),
            'hideOptions' => Craft::t('influx', 'Hide options'),
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
     *
     * This is the template: it reads the current field value once and hands
     * both values to {@see valueDiffers()}, which subclasses override to
     * express their comparison semantics. Reading the field value can throw
     * (a related-element query failing, a field mid-migration, ...); an
     * unreadable current value ⇒ assume changed so the write still happens.
     */
    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        try {
            $current = $context->element->getFieldValue($context->handle);

            return $this->valueDiffers($context, $current, $incoming);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Whether the feed addresses this mapping for the given item — the gate
     * {@see \GlueAgency\Influx\sync\MappingApplier} consults before running the
     * strategy at all. Default: the mapping's own node/default addressing.
     * Strategies whose value derives from SUB-mappings rather than an own node
     * ({@see Matrix}) override this, because a node-less parent mapping would
     * otherwise always read as unaddressed.
     */
    public function addressed(FieldContext $context): bool
    {
        return $context->mapping->addressedBy($context->item);
    }

    // -- shared helpers ----------------------------------------------------

    /**
     * Compare the element's current field value against the incoming one.
     * Called by {@see hasChanged()} with the already-read current value, so
     * the read-failure guard lives in one place. Default: normalise both
     * sides and compare — subclasses override for type-specific semantics
     * (id-set comparison, timestamp comparison, HTML-serialisation, ...).
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        return $this->normalize($current) !== $this->normalize($incoming);
    }

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
            return (string) $value;
        }

        if ($value instanceof Stringable) {
            $str = (string) $value;

            return $str === '' ? null : $str;
        }

        return json_encode($value);
    }
}
