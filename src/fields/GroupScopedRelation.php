<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Shared base for the group-scoped relation flavours — {@see Categories} and
 * {@see Tags}. Both point at exactly ONE group through a single
 * `<prefix>:UID` source key, so lookups narrow to that group's id, its layout
 * drives the match-by / sub-field options, and a created element is placed in
 * it.
 *
 * Subclasses supply only what actually varies: the source-key prefix, the group
 * table its UID resolves against in this environment, and the service call that
 * reaches the group's field layout.
 */
abstract class GroupScopedRelation extends Relation
{
    /**
     * Prefix of this flavour's field source key — `group:` for categories,
     * `taggroup:` for tags.
     */
    abstract protected function sourcePrefix(): string;

    /**
     * Table holding the groups, whose row id the source UID resolves to (ids
     * differ per environment, UIDs don't).
     */
    abstract protected function groupTable(): string;

    /**
     * Field layout of the group the given UID identifies, or null when this
     * environment has no such group.
     */
    abstract protected function groupFieldLayout(string $uid): ?FieldLayout;

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $uid = $this->sourceUid($field->source ?? null, $this->sourcePrefix());

        if ($uid === null) {
            return;
        }

        $layout = $this->groupFieldLayout($uid);

        if ($layout) {
            yield $layout;
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId !== null) {
            /** @phpstan-ignore-next-line — category/tag queries expose groupId */
            $query->groupId($groupId);
        }
    }

    /**
     * Create the element in the field's configured group. An unresolvable group
     * means there's nowhere to put it, so nothing is created — bailing beats
     * guessing a group.
     */
    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId === null) {
            return null;
        }

        $class = $this->elementType();
        /** @var ElementInterface $element */
        $element = new $class();
        $element->groupId = $groupId;
        $element->title = (string) $value;

        return Craft::$app->getElements()->saveElement($element, true) ? $element : null;
    }

    /** Group id (this environment) from a `<prefix>:UID` source key, or null. */
    protected function sourceGroupId(mixed $source): ?int
    {
        return $this->sourceIdByUid($source, $this->sourcePrefix(), $this->groupTable());
    }
}
