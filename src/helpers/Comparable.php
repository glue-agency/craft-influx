<?php

namespace GlueAgency\Influx\helpers;

use craft\base\ElementInterface;
use DateTimeInterface;
use Stringable;

/**
 * THE change-detection normaliser. Both layers that decide "did this write
 * actually change anything" route their values through it — the custom-field
 * strategies ({@see \GlueAgency\Influx\fields\Field::normalize()}) and the
 * native-attribute path ({@see \GlueAgency\Influx\targets\AbstractElementTarget::comparable()})
 * — so the same value means the same thing on both sides.
 */
class Comparable
{
    /**
     * Stable, type-aware representation of a value: two semantically-equal
     * values must produce the same one. Empty is null, a boolean false is a real
     * value (not "empty"), dates compare by timestamp and related elements by
     * id — so re-applying the same flag / date / relation isn't mistaken for a
     * change. Anything else falls back to its string or JSON form.
     */
    public static function of(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if ($value instanceof ElementInterface) {
            return (int) $value->id;
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            $str = (string) $value;

            return $str === '' ? null : $str;
        }

        return json_encode($value);
    }
}
