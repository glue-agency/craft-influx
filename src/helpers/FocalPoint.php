<?php

namespace GlueAgency\Influx\helpers;

use GlueAgency\Influx\exceptions\MappingValueException;

/**
 * An asset's focal point as a feed carries it: Craft's own `x;y` form, each
 * coordinate between 0 and 1 measured from the top-left corner.
 */
class FocalPoint
{
    /**
     * The value as the `{x, y}` array `Asset::setFocalPoint()` takes, rounded to
     * the four decimals Craft stores so a re-run compares equal; null when empty.
     *
     * Stricter than Craft's setter on purpose: it reads `left;top` as the
     * top-left corner and drops an out-of-range point to none, so either would
     * overwrite a stored point with one the feed never meant.
     *
     * @return array{x: float, y: float}|null
     * @throws MappingValueException when the value isn't an `x;y` pair inside 0–1.
     */
    public static function parse(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $coordinates = is_string($value) ? explode(';', $value) : [];

        if (count($coordinates) !== 2 || ! static::isCoordinate($coordinates[0]) || ! static::isCoordinate($coordinates[1])) {
            $display = is_scalar($value) ? (string) $value : json_encode($value);

            throw new MappingValueException("Unparseable focal point '{$display}': expected 'x;y', each between 0 and 1.");
        }

        return [
            'x' => round((float) $coordinates[0], 4),
            'y' => round((float) $coordinates[1], 4),
        ];
    }

    protected static function isCoordinate(string $value): bool
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1;
    }
}
