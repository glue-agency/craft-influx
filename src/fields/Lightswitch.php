<?php

namespace TDM\Influx\fields;

/**
 * Coerces an arbitrary feed value (string/number/bool) into a boolean using a
 * user-configurable "truthy values" list.
 *
 *   options.truthy: ['true', '1', 'yes', 'on']  // defaults
 *
 * Anything else, incl. null, becomes false.
 */
class Lightswitch extends Field
{
    private const DEFAULT_TRUTHY = ['true', '1', 'yes', 'on'];

    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Lightswitch::class;
    }

    public function parseField(): mixed
    {
        $raw = $this->fetchSimpleValue();
        if (is_bool($raw)) {
            return $raw;
        }

        $truthy = $this->fieldInfo['options']['truthy'] ?? self::DEFAULT_TRUTHY;
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
