<?php

namespace GlueAgency\Influx\fields;

use craft\fields\Lightswitch as CraftLightswitchField;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Coerces an arbitrary remote value (string/number/bool) into a boolean
 * automatically — no per-mapping configuration. Booleans pass through;
 * everything else is matched (case-insensitively) against the truthy
 * spellings feeds actually ship. Anything unrecognized, incl. null,
 * becomes false.
 */
class Lightswitch extends Field
{
    /** Referenced by {@see \GlueAgency\Influx\targets\EntryTarget::parseEnabled()} too — keep public. */
    public const TRUTHY_VALUES = ['true', '1', 'yes', 'on'];

    public static function craftFieldClass(): ?string
    {
        return CraftLightswitchField::class;
    }

    /**
     * An unmapped row (no node, no default) yields null so the walker leaves
     * the field untouched rather than forcing false; a mapped-but-empty value
     * still coerces.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null && ! $context->mapping->isActive()) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) $raw)), self::TRUTHY_VALUES, true);
    }
}
