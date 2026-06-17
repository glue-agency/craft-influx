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

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        // No node and no default — the field isn't mapped at all. Yield null so
        // the walker leaves it untouched instead of forcing it to false on
        // every sync. (An actively-mapped-but-empty value still coerces to
        // false below: the feed is authoritative, and an empty boolean is false.)
        if ($raw === null && ! $context->mapping->isActive()) {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) $raw)), self::TRUTHY_VALUES, true);
    }
}
