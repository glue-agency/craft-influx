<?php

namespace GlueAgency\Influx\fields;

use craft\fields\Lightswitch as CraftLightswitchField;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Coerces an arbitrary remote value (string/number/bool) into a boolean
 * automatically — no per-mapping configuration. {@see coerce()} owns those
 * semantics for the whole plugin.
 *
 * Two distinct nulls: an UNADDRESSED row (no node, no default) makes
 * {@see parse()} return null so the walker leaves the field untouched, whereas a
 * null VALUE on an addressed row is coerced like any other unrecognized value —
 * to false.
 */
class Lightswitch extends Field
{
    /** The truthy spellings feeds actually ship; {@see coerce()} owns the matching. */
    public const TRUTHY_VALUES = ['true', '1', 'yes', 'on'];

    public static function craftFieldClass(): ?string
    {
        return CraftLightswitchField::class;
    }

    /**
     * THE truthy coercion: booleans pass through, everything else is matched
     * (case-insensitively, trimmed) against {@see TRUTHY_VALUES}, and anything
     * unrecognized — null included — is false. Also used for the native
     * `enabled` attribute ({@see \GlueAgency\Influx\targets\AbstractElementTarget::parseEnabled()}),
     * so a feed spelling means the same thing on a field and on an element flag.
     */
    public static function coerce(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            default         => in_array(strtolower(trim((string) $value)), self::TRUTHY_VALUES, true),
        };
    }

    /**
     * An unaddressed row yields null so the field is left untouched; every other
     * value — empty included — goes through {@see coerce()}.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null && ! $context->mapping->isActive()) {
            return null;
        }

        return self::coerce($raw);
    }
}
