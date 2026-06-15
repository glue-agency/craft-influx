<?php

namespace GlueAgency\Influx\fields;

use craft\base\FieldInterface as CraftFieldInterface;
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

    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [
            'kind' => 'boolean',
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) $raw)), self::TRUTHY_VALUES, true);
    }
}
