<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\helpers\Db;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Relation strategy for group-scoped element types — {@see Categories}
 * (`group:UID` sources, the `categorygroups` table) and {@see Tags}
 * (`taggroup:UID`, `taggroups`). Both resolve a single source group to its
 * field layout, scope lookups to that group, and create missing elements in
 * it; only the source prefix, group table, group-service lookup, and element
 * class differ — supplied by subclasses as the four hooks below.
 */
abstract class GroupRelation extends Relation
{
    /** Source-key prefix this relation's Craft field uses (`group:` / `taggroup:`). */
    abstract protected function sourcePrefix(): string;

    /** Group table, for resolving a source UID to a group id in this environment. */
    abstract protected function groupTable(): string;

    /** Field layout for a source group UID, or null (group-service specific). */
    abstract protected function groupLayout(string $uid): ?FieldLayout;

    /** A new, unsaved element of this type in the given group, titled $title. */
    abstract protected function newGroupElement(int $groupId, string $title): ElementInterface;

    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $uid = $this->sourceUid($field->source ?? null, $this->sourcePrefix());

        if ($uid === null) {
            return;
        }

        $layout = $this->groupLayout($uid);

        if ($layout) {
            yield $layout;
        }
    }

    protected function scopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId !== null) {
            /** @phpstan-ignore-next-line — group element queries expose groupId */
            $query->groupId($groupId);
        }
    }

    protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        $groupId = $this->sourceGroupId($context->craftField?->source ?? null);

        if ($groupId === null) {
            return null;
        }

        $element = $this->newGroupElement($groupId, (string) $value);

        return Craft::$app->getElements()->saveElement($element, true) ? $element : null;
    }

    /** Group id (this environment) from a source key matching the prefix, or null. */
    protected function sourceGroupId(mixed $source): ?int
    {
        $uid = $this->sourceUid($source, $this->sourcePrefix());

        if ($uid === null) {
            return null;
        }

        $id = Db::idByUid($this->groupTable(), $uid);

        return $id ? (int) $id : null;
    }
}
