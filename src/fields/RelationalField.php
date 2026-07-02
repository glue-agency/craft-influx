<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\MappingApplier;

/**
 * Shared base for fields that store related-element ids and may write values
 * back to those related elements — {@see Relation} (Entries / Users /
 * Categories / Tags) and {@see Assets}. Factors out the two behaviours both
 * implement identically: comparing the field by its id-set, and persisting a
 * related element after its sub-mappings run.
 *
 * Assets deliberately does NOT extend {@see Relation} (it matches by id/url,
 * not the match-by lookup machinery) — but it IS relational in these two
 * respects, so the shared logic lives here rather than being copy-pasted.
 */
abstract class RelationalField extends Field
{
    /**
     * Relational fields compare by their ordered list of related ids. The
     * comparison is order-SENSITIVE: relation and asset fields persist their
     * order, so a feed that reorders the same ids is a real change. A
     * null/empty parse clears the field, so it counts as changed only when ids
     * currently exist — clearing an already-empty field is not a needless save.
     *
     * `$current` is the field's element query; resolving it via `ids()` runs
     * inside {@see Field::hasChanged()}'s try, so a failing query still lands
     * on the "assume changed" guard exactly as before.
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $currentIds = $current?->ids() ?? [];
        $incomingIds = is_array($incoming) ? array_values($incoming) : [];

        return array_map('intval', array_values($currentIds)) !== array_map('intval', $incomingIds);
    }

    /**
     * Write the related-element ids onto the field. A null/empty parse MUST be
     * written as an explicit empty array, never null: Craft relation fields
     * read null as "no value supplied — keep the existing relations"
     * ({@see \craft\fields\BaseRelationField::normalizeValue()} re-reads the
     * current ids from the `relations` table when the value is null), so
     * passing null leaves the relation intact instead of clearing it. The
     * applier only reaches apply() for a field the feed addresses, so an empty
     * value here always means "the feed cleared this" — coerce it to [] so the
     * related elements are actually detached on save.
     */
    public function apply(FieldContext $context, mixed $value): bool
    {
        $context->element->setFieldValue($context->handle, $value ?? []);

        return true;
    }

    /**
     * Apply this mapping's sub-mappings to a related element and persist it,
     * but only when a sub-mapping actually changed a value. Skipped under dry-
     * run: the related element is a real, saved element the debug inspector
     * must not mutate. The walk itself ({@see MappingApplier::applySubMappings()})
     * never saves; persistence is decided here.
     */
    protected function persistSubElement(FieldContext $context, ElementInterface $element): void
    {
        if ($context->dryRun) {
            return;
        }

        if ((new MappingApplier())->applySubMappings($context, $element)) {
            Craft::$app->getElements()->saveElement($element, false);
        }
    }

    /**
     * Extract the UID from a Craft field source key (`section:UID`,
     * `group:UID`, `taggroup:UID`, `volume:UID`, ...) when it matches the given
     * prefix, or null. Subclasses decode their own source flavour through this
     * rather than repeating the `str_starts_with` + `explode` dance.
     *
     * Lives here rather than on {@see Relation} because {@see Assets} (which
     * extends this base directly, not Relation) resolves `volume:` sources the
     * same way.
     */
    protected function sourceUid(mixed $source, string $prefix): ?string
    {
        if (! is_string($source) || ! str_starts_with($source, $prefix)) {
            return null;
        }

        return explode(':', $source)[1] ?? null;
    }

    /**
     * Normalise a resolved node value into the flat list a relational parse
     * iterates. A single source node can carry one value or an array of them
     * (a JSON array of ids/urls), and empty entries (null / '') within that
     * list are dropped so a stray blank doesn't turn into a lookup for nothing.
     *
     * @return list<mixed>
     */
    protected function referenceValues(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_filter($values, static fn(mixed $value): bool => $value !== null && $value !== ''));
    }
}
