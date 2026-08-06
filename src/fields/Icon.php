<?php

namespace GlueAgency\Influx\fields;

use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\data\IconData;
use craft\fields\Icon as CraftIconField;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Icon mapping strategy (Craft 5).
 *
 * Craft stores an icon as its bare Font Awesome name — `user`, not `fa-user`
 * ({@see \craft\fields\Icon::normalizeValue()} wraps the string verbatim in an
 * {@see IconData}). Feeds and design systems overwhelmingly carry the prefixed
 * form, and on the DefaultField fallback that landed as-is: the CP icon picker
 * showed nothing (no icon is named `fa-user`) and the field rewrote itself on
 * every sync, since the stored `fa-user` never matched what the picker would
 * have saved.
 *
 * Stripping the prefix on both sides is the whole strategy. Matching by anything
 * other than the name is deliberately not offered — the set runs to thousands of
 * icons whose human labels aren't unique, so the name IS the identifier.
 */
class Icon extends Field
{
    /** The prefix Font Awesome's own class names carry and Craft's stored names don't. */
    protected const PREFIX = 'fa-';

    public static function craftFieldClass(): ?string
    {
        return CraftIconField::class;
    }

    /**
     * The default is one of Font Awesome's several thousand icons, so the row
     * mounts Craft's OWN icon picker rather than any list of its own — the same
     * trade the element default makes ({@see Assets::schema()} and the
     * native author row): a server round-trip for the real CP control.
     *
     * Craft searches that set server-side already (`app/icon-picker-options`,
     * over each icon's search TERMS — so "car" finds "automobile") and gates Pro
     * icons on the field's own `includeProIcons`. A select over icon names would
     * ship 3,800 rows to do a worse job of it.
     *
     * The picker's SHAPE is the server's call, derived from the field the same way
     * an element picker's sources are, so nothing about Pro gating rides the
     * descriptor ({@see \GlueAgency\Influx\services\LinkBuilderService::renderIconPicker()}).
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => fn(MappingSchemaBuilder $b) => $b->node(MappingSchemaBuilder::ICON),
        ]);
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed. An unrecognised name is NOT an error here: Craft accepts any string
     * and the icon set depends on whether Pro icons are enabled, so validating
     * against it would reject icons the site can legitimately render.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        return self::iconName($raw);
    }

    /**
     * The bare icon name: an {@see IconData} unwrapped, a prefixed name stripped,
     * anything else trimmed. Null when there's no name left.
     */
    public static function iconName(mixed $value): ?string
    {
        if ($value instanceof IconData) {
            $value = $value->name;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $name = trim((string) $value);

        if (str_starts_with($name, self::PREFIX)) {
            $name = substr($name, strlen(self::PREFIX));
        }

        return $name !== '' ? $name : null;
    }

    /**
     * Reduce both sides to the bare name, so a feed's `fa-user` and a stored
     * `user` are one value. A non-icon keeps the base normal form.
     */
    protected function normalize(mixed $value): mixed
    {
        return parent::normalize(self::iconName($value) ?? $value);
    }
}
