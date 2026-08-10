<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use yii\base\Model;

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
     * A relation field stores element IDS, so it can't identify the element that
     * holds it: the only feed value that would ever match is Craft's own id for the
     * RELATED element. Covers Entries / Users / Categories / Tags (through
     * {@see Relation}) and Assets. See {@see Field::matchable()}.
     */
    public static function matchable(): bool
    {
        return false;
    }

    /**
     * Relational fields compare by their ordered list of related ids. The
     * comparison is order-SENSITIVE: relation and asset fields persist their
     * order, so a feed that reorders the same ids is a real change. A
     * null/empty parse clears the field, so it counts as changed only when ids
     * currently exist — clearing an already-empty field is not a needless save.
     *
     * `$current` is the field's element query; resolving it runs inside
     * {@see Field::hasChanged()}'s try, so a failing query still lands on the
     * "assume changed" guard exactly as before.
     *
     * A field that maintains hierarchy is compared as a SET, and only in one
     * direction: Craft fills the gaps in a structured relation on save
     * ({@see \craft\fields\BaseRelationField::normalizeValue()} →
     * `Structures::fillGapsInElements()`), so the stored side legitimately holds
     * ancestors the feed never sent and an equality check could never hold. What
     * a change means there is "the feed now asks for an id the element isn't
     * related to".
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        $currentIds = array_map('intval', array_values($this->relatedIds($current)));
        $incomingIds = array_map('intval', array_values(is_array($incoming) ? $incoming : []));

        if ($this->maintainsHierarchy($context)) {
            return array_diff($incomingIds, $currentIds) !== []
                || ($incomingIds === [] && $currentIds !== []);
        }

        return $currentIds !== $incomingIds;
    }

    /**
     * Every id the field currently relates, read the way Craft reads a relation
     * for serialization ({@see \craft\fields\BaseRelationField} `_all()`).
     *
     * The value Craft hands back is a query still carrying its own defaults —
     * enabled elements, one site — so a plain `ids()` silently omits a related
     * element that happens to be disabled, a draft, or resident in another site.
     * The stored side then under-reports, the comparison can never match, and
     * the field reads as changed on every single sync: a needless save and a
     * revision per run, on an element nothing in the feed touched. Relaxing the
     * query is what makes the two sides speak about the same set.
     *
     * @return list<int|string>
     */
    protected function relatedIds(mixed $current): array
    {
        if ($current instanceof ElementQueryInterface) {
            return (clone $current)
                ->status(null)
                ->drafts(null)
                ->site('*')
                ->unique()
                ->limit(null)
                ->ids();
        }

        // Craft 5 hands an already-resolved collection back for an eager-loaded
        // field; it holds what it holds, so there is no query to relax.
        if (is_object($current) && method_exists($current, 'ids')) {
            return $current->ids();
        }

        return [];
    }

    /**
     * Whether the Craft field keeps a structured relation whole — `@since 5.0`,
     * so absence of the property reads as "off" rather than throwing on Craft 4.
     */
    protected function maintainsHierarchy(FieldContext $context): bool
    {
        $craftField = $context->craftField;

        return $craftField !== null
            && property_exists($craftField, 'maintainHierarchy')
            && $craftField->maintainHierarchy;
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
     *
     * "The feed cleared this" is the ONLY way an empty value reaches here: a
     * reference the feed did carry but whose element could not be CREATED throws
     * out of the parse instead of resolving to nothing
     * ({@see Relation::persistNewElement()}), so this method never runs for that
     * row and the stored relations survive. Clearing is always a decision the
     * feed made, never a failure wearing an empty value's clothes.
     */
    public function apply(FieldContext $context, mixed $value): bool
    {
        $context->element->setFieldValue($context->handle, $value ?? []);

        return true;
    }

    /**
     * Every relational flavour nests the elements it relates, so the noun is the
     * generic one here; concrete flavours narrow it to their own ({@see Assets},
     * {@see Entries}, ...).
     */
    public function childrenKind(): ?string
    {
        return 'elements';
    }

    /**
     * Field layouts of the elements this field points at, resolved from the
     * field's configured sources. Subclasses know how to translate their own
     * source keys into the right layouts — a relation's sections / groups
     * (`section:UID`, `group:UID`, ...), an asset field's volumes — and
     * override accordingly; the base returns nothing so a flavour that hasn't
     * declared its sources still builds a sensible (built-ins-only) schema.
     *
     * @return iterable<FieldLayout|null>
     */
    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        return [];
    }

    /**
     * The rows of the ONE sub-field card a relational mapping offers: the
     * related element's own attributes first, then the custom fields of the
     * layouts its sources allow, deduped by handle.
     *
     * The two halves are written through different channels, so the custom rows
     * are marked `fields` while the natives stay unmarked — an unmarked row
     * means `nativeFields`, which is both what these rows were stored in before
     * the key existed and the safe default, since a native routed to `fields`
     * is dropped silently at apply time. See
     * {@see \GlueAgency\Influx\schema\MappingSchemaBuilder::elementSubFields()}.
     *
     * A native wins a handle collision. It can only happen where the attribute
     * isn't a reserved Craft field handle — `email` on a user, `alt` on an asset
     * — and one handle-keyed table can hold only one of them.
     *
     * @return list<array>
     */
    protected function subFieldRows(BaseRelationField $field): array
    {
        $rows = $this->nativeSubFields($field);
        $seen = array_column($rows, 'handle');

        foreach ($this->layoutCustomSubFields($field) as $row) {
            if (in_array($row['handle'] ?? null, $seen, true)) {
                continue;
            }

            $rows[] = $row + ['channel' => 'fields'];
        }

        return $rows;
    }

    /**
     * The related element's own attributes a mapping can write, as sub-field
     * rows. Per element type, so each flavour overrides; the base offers none.
     *
     * @return list<array>
     */
    protected function nativeSubFields(BaseRelationField $field): array
    {
        return [];
    }

    /**
     * One sub-node per custom field across the related element's source layouts
     * — the sub-fields a mapping's `fields` channel can address
     * ({@see \GlueAgency\Influx\sync\item\MappingApplier::applySubMappings()}
     * resolves each handle on the related element's own field layout). Deduped
     * by handle, first layout wins (mirrors {@see Relation::matchOptions()}'
     * `$seen` set), because the union across sources is what the field may
     * relate and the same handle means the same field wherever it appears.
     *
     * Each row carries the default-value editor its own field asks for
     * ({@see MappingSchemaBuilder::fieldRow()}), so a relation sub-field offers the
     * element picker its top-level row would.
     *
     * @return list<array>
     */
    protected function layoutCustomSubFields(BaseRelationField $field): array
    {
        $builder = MappingSchemaBuilder::make();
        $seen = [];

        foreach ($this->sourceFieldLayouts($field) as $layout) {
            if (! $layout instanceof FieldLayout) {
                continue;
            }

            foreach ($layout->getCustomFields() as $customField) {
                $handle = $customField->handle;

                if (isset($seen[$handle])) {
                    continue;
                }

                $seen[$handle] = true;
                $builder->fieldRow($this->childRowFor($customField), [
                    'handle' => $handle,
                    'label'  => $customField->name,
                ]);
            }
        }

        return $builder->toArray();
    }

    /**
     * Apply this mapping's sub-mappings to a related element and persist it,
     * but only when a sub-mapping actually changed a value. Skipped under dry-
     * run: the related element is a real, saved element the debug inspector
     * must not mutate. The walk itself
     * ({@see \GlueAgency\Influx\sync\item\MappingApplier::applySubMappings()})
     * never saves; persistence is decided here, and it rides the context
     * ({@see FieldContext::applySubMappings()}) so this class never builds an
     * applier of its own.
     *
     * Also REPORTS what happened: every related element this touches becomes a
     * child of the parent row — created / updated / unchanged — for the run log's
     * drill-down. Unchanged ones are reported too, because the drill-down shows
     * every element the feed addressed, not only the ones it rewrote: "nothing to
     * write" is an answer the log has to be able to give. A $created element with
     * no sub-mappings at all still reports (creation is itself a nested write);
     * one that merely got linked doesn't — nothing happened to it beyond the
     * relation, which is the parent row's own business.
     *
     * A REFUSED save throws. The sweeper's discipline holds for related elements
     * too — "a save that returns false WITHOUT throwing (a validation failure
     * that didn't persist) is an ERROR row, never a success row"
     * ({@see \GlueAgency\Influx\sync\run\MissingElementsSweeper::apply()}) — and
     * discarding the return here was exactly that: the related element silently
     * kept its old values while the parent row logged success. The throw lands on
     * the parent mapping's row, the only row a sub-element has
     * ({@see \GlueAgency\Influx\sync\item\MappingApplier::applyCustomField()}) —
     * so a refused save reports NO child either: the failure belongs on that row,
     * not on a child claiming the element was written.
     *
     * @throws MappingValueException when the related element refuses to save
     */
    protected function persistSubElement(FieldContext $context, ElementInterface $element, bool $created = false): void
    {
        if ($context->dryRun) {
            return;
        }

        if (! $context->mapping->hasSubMappings()) {
            if ($created) {
                $this->reportChild($context, $element, ChildAction::CREATED);
            }

            return;
        }

        $outcome = $context->applySubMappings($element);
        $changed = $outcome->changed();

        if ($changed && ! $this->saveSubElement($element)) {
            throw new MappingValueException($this->saveFailureMessage($element));
        }

        $action = ChildAction::UNCHANGED;

        if ($created) {
            $action = ChildAction::CREATED;
        } elseif ($changed) {
            $action = ChildAction::UPDATED;
        }

        $this->reportChild($context, $element, $action, $outcome->results);
    }

    /**
     * Report one touched related element to the walk's collector, which attaches
     * it to the row being built ({@see \GlueAgency\Influx\sync\item\MappingApplier::mapCustomField()}).
     * A context without a collector (a strategy exercised directly) simply
     * reports nothing.
     *
     * The COMMITTED action value is right by construction: every caller sits past
     * {@see persistSubElement()}'s dry-run guard, so there is no hypothetical
     * write here to label 'would-*' ({@see ChildAction::dryRunLabel()}).
     *
     * @param list<MappingResult> $mappingResults
     */
    protected function reportChild(FieldContext $context, ElementInterface $element, ChildAction $action, array $mappingResults = []): void
    {
        $context->childCollector?->add(new ChildResult(
            title: (string) $element->getUiLabel(),
            element: $element,
            labelElement: $element,
            action: $action->value,
            mappingResults: $mappingResults,
        ));
    }

    /**
     * The related-element save, extracted so tests can stub persistence without
     * booting Craft.
     */
    protected function saveSubElement(ElementInterface $element): bool
    {
        return Craft::$app->getElements()->saveElement($element, false);
    }

    /**
     * Name the sub-element that refused to save, with its validation errors when
     * it carries any — a bare "save failed" on a nested element the feed also
     * fills is unactionable.
     */
    protected function saveFailureMessage(ElementInterface $element): string
    {
        $label = $element->getUiLabel();

        $who = array_filter([
            $element->id !== null ? "#{$element->id}" : null,
            $label !== '' ? "'{$label}'" : null,
        ]);

        return $this->withValidationErrors(rtrim('Failed to save related element ' . implode(' ', $who)) . '.', $element);
    }

    /**
     * Append an element's validation errors to a refused-write message when it
     * carries any — they're the whole story of why Craft said no. Errors are read
     * through {@see Model} rather than ElementInterface, which declares no error
     * API of its own.
     *
     * Split out from {@see saveFailureMessage()} because a refused CREATE
     * ({@see Relation::createFailureMessage()}) needs the same tail under a
     * different "who": the element it names doesn't exist yet, so it has neither
     * an id nor a trustworthy UI label — only the feed value it was built from.
     */
    protected function withValidationErrors(string $message, ElementInterface $element): string
    {
        $errors = $element instanceof Model ? $element->getFirstErrors() : [];

        if ($errors === []) {
            return $message;
        }

        $parts = [];

        foreach ($errors as $attribute => $error) {
            $parts[] = "{$attribute}: {$error}";
        }

        return $message . ' ' . implode('; ', $parts);
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
