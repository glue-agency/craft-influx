<?php

namespace TDM\Influx\fields;

use TDM\Influx\sync\FieldContext;

/**
 * Coerces an arbitrary remote value (string/number/bool) into a boolean using
 * a user-configurable "truthy values" list.
 *
 *   options.truthy: ['true', '1', 'yes', 'on']  // defaults
 *
 * Anything else, incl. null, becomes false.
 */
class Lightswitch extends Field
{
    protected const DEFAULT_TRUTHY = ['true', '1', 'yes', 'on'];

    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Lightswitch::class;
    }

    public function fieldMeta(\craft\base\FieldInterface $field): array
    {
        return [
            'kind'   => 'boolean',
            'labels' => self::extrasLabels() + self::commonExtrasLabels(),
        ];
    }

    /**
     * UI strings rendered inside the boolean extras block. Kept around even
     * though the extras component no longer mounts for booleans, so the
     * dormant template branch keeps reading from a single source.
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'truthyLabel'       => \Craft::t('influx', 'Truthy values'),
            'truthyPlaceholder' => \Craft::t('influx', 'true, 1, yes, on'),
            'truthyHint'        => \Craft::t('influx', 'Comma-separated. Anything else (incl. null) maps to false.'),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);
        if (is_bool($raw)) {
            return $raw;
        }

        $truthy = $context->mapping->option('truthy');
        if (!is_array($truthy)) {
            $truthy = self::DEFAULT_TRUTHY;
        }

        $lc = strtolower((string)$raw);
        foreach ($truthy as $candidate) {
            if (strtolower((string)$candidate) === $lc) {
                return true;
            }
        }
        return false;
    }
}
