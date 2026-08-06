<?php

namespace GlueAgency\Influx\fields;

use craft\helpers\DateTimeHelper;
use DateTimeInterface;

/**
 * Craft's table-cell type semantics — what a typed cell stores, and what it
 * reduces to for comparison.
 *
 * Shared because two strategies write a table of typed cells and must agree on
 * every one of them: {@see Table}, over `craft\fields\Table`, and
 * {@see \GlueAgency\Influx\integrations\verbb\tablemaker\TableMakerField}, over
 * Table Maker's — which copied Craft's `_normalizeCellValue()` verbatim, down to
 * the type strings, so the semantics are the same by construction rather than by
 * coincidence.
 *
 * What differs between those two is the SHAPE around the cells (whose columns,
 * keyed or positional, and where the column list comes from), never the cells.
 * So this holds only the leaf, and each strategy keeps its own shape handling.
 *
 * Both halves matter and they aren't the same operation: {@see coerceCell()}
 * prepares a feed value for STORAGE, {@see cellPrint()} reduces either side for
 * COMPARISON. Comparison has to go further, because the stored side has already
 * been through Craft's normalize — a checkbox is a real bool there and a date is
 * a DateTime, while the feed still spells them "yes" and an ISO string. Skipping
 * that leaf reduction is what made a Matrix lightswitch re-save on every run
 * (CHANGELOG alpha.6).
 */
trait TableCells
{
    /** Column types whose cell is a flag rather than a value. */
    protected const BOOLEAN_TYPES = ['checkbox', 'lightswitch'];

    /** Column types whose cell is free text, and so compares trimmed. */
    protected const TEXT_TYPES = ['singleline', 'multiline'];

    /**
     * Coerce one feed value into what its column stores. Craft's
     * `_normalizeCellValue()` covers color, number, date and time but has no
     * case for the two flag types, so those are coerced here; text columns are
     * trimmed the way that same normaliser trims them, and every other type is
     * handed over raw for Craft to normalize.
     */
    protected function coerceCell(string $type, mixed $value): mixed
    {
        return match (true) {
            in_array($type, self::BOOLEAN_TYPES, true) => Lightswitch::coerce($value),
            in_array($type, self::TEXT_TYPES, true) => $this->trimmed($value),
            default => $value,
        };
    }

    /**
     * Reduce one cell to its comparable form, by column type. Flags coerce, date
     * and time columns compare by instant, text columns trim, and everything
     * else lands on the shared normaliser ({@see Field::normalize()}) — which
     * already handles the color column's ColorData (Stringable) and the number
     * column's numeric strings.
     */
    protected function cellPrint(string $type, mixed $value): mixed
    {
        return match (true) {
            in_array($type, self::BOOLEAN_TYPES, true) => Lightswitch::coerce($value),
            in_array($type, self::TEXT_TYPES, true) => $this->normalize($this->trimmed($value)),
            $type === 'date', $type === 'time' => $this->instant($value),
            default => $this->normalize($value),
        };
    }

    /**
     * A date/time cell as its instant. Parsed with both timezone flags off so a
     * timezone-less value reads as UTC on either side — which is how Craft's own
     * `normalizeValue()` reads the stored one — and so the comparison stays
     * boot-free. A value no date parse accepts keeps the base normal form rather
     * than collapsing to null, so non-dates stay distinguishable ({@see Date::normalize()}
     * takes the same stance).
     */
    protected function instant(mixed $value): mixed
    {
        if (! is_string($value) && ! is_int($value) && ! $value instanceof DateTimeInterface) {
            return $this->normalize($value);
        }

        $date = DateTimeHelper::toDateTime($value, false, false);

        return $date !== false ? $date->getTimestamp() : $this->normalize($value);
    }

    /** A scalar as its trimmed string; anything else unchanged. */
    protected function trimmed(mixed $value): mixed
    {
        return is_scalar($value) ? trim((string) $value) : $value;
    }
}
