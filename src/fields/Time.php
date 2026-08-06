<?php

namespace GlueAgency\Influx\fields;

use craft\fields\Time as CraftTimeField;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\sync\FieldContext;
use Throwable;

/**
 * Time-of-day mapping strategy.
 *
 * On the DefaultField fallback this field rewrote itself on every single sync.
 * Craft normalizes a Time value to a DATETIME
 * ({@see \craft\fields\Time::normalizeValue()}) but serializes it to an `H:i:s`
 * STRING ({@see \craft\fields\Time::serializeValue()}), so the stored side and
 * the feed's `"09:30"` never reduced to the same thing — the raw write put a
 * string in, the comparison read a DateTime back, and the element was re-saved
 * (cutting a revision) on every run.
 *
 * The fix is the same shape {@see Date} uses, one notch narrower: {@see parse()}
 * hands Craft the DateTime it wants, and {@see normalize()} reduces BOTH sides to
 * the clock time so the date part — which a Time field doesn't store and Craft
 * fills in arbitrarily — can't make two identical times look different.
 *
 * No format option, unlike {@see Date}: a clock string has few enough spellings
 * ({@see FORMATS}) to just try them all, and none of them is ambiguous the way
 * `02/03/2024` is. A feed shipping a full datetime for a time field still lands,
 * through the {@see Date::tryParse()} fallback.
 */
class Time extends Field
{
    /** The comparable form: clock time only, the same shape Craft serializes to. */
    protected const COMPARE_FORMAT = 'H:i:s';

    /**
     * The clock spellings feeds ship, most specific first. Each is `!`-prefixed
     * so the unspecified date part lands on the epoch instead of today — a Time
     * field doesn't store a date, and letting it default to "now" would make two
     * reads of the same value differ across midnight.
     */
    protected const FORMATS = ['!H:i:s', '!H:i', '!g:i:s A', '!g:i A'];

    public static function craftFieldClass(): ?string
    {
        return CraftTimeField::class;
    }

    /**
     * Parse a feed value into the DateTime Craft's Time field normalizes to.
     *
     * The clock formats are tried first, and exactly — a partial match is
     * rejected, so `09:30:45` is read by `H:i:s` rather than truncated by `H:i`.
     * Only a value none of them accepts falls through to {@see Date::tryParse()},
     * which is what lets a feed reusing a full ISO timestamp for a time field
     * still yield the right hour.
     */
    public static function tryParse(mixed $raw): ?DateTime
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw instanceof DateTime ? $raw : DateTime::createFromInterface($raw);
        }

        if (! is_scalar($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        foreach (self::FORMATS as $format) {
            $parsed = DateTime::createFromFormat($format, $value, new DateTimeZone('UTC'));

            if ($parsed !== false && self::parsedCleanly()) {
                return $parsed;
            }
        }

        // The datetime fallback reaches Craft's own auto-detector, which wants a
        // booted app for its timezone lookup and raises rather than returns on
        // input it can't use at all. This method's whole contract is "null when
        // it isn't a time", so nothing from down there may escape as a fatal.
        try {
            return Date::tryParse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the last `createFromFormat()` consumed the whole value. Trailing
     * data only raises a WARNING, so a lenient check would let `H:i` swallow
     * `09:30:45` and silently drop its seconds. PHP 8.2 returns false here when
     * there was nothing to report; 8.1 returns zeroed counts.
     */
    protected static function parsedCleanly(): bool
    {
        $errors = DateTime::getLastErrors();

        if ($errors === false) {
            return true;
        }

        return ($errors['error_count'] ?? 0) === 0 && ($errors['warning_count'] ?? 0) === 0;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed.
     *
     * @throws MappingValueException when a present value can't be read as a time —
     * malformed data must surface as an error row rather than silently leaving
     * the field untouched, the same policy {@see Date::parse()} applies.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $parsed = self::tryParse($raw);

        if ($parsed === null) {
            $display = is_scalar($raw) ? (string) $raw : gettype($raw);

            throw new MappingValueException("Unparseable time value '{$display}'.");
        }

        return $parsed;
    }

    /**
     * Reduce every shape a Time value reaches a comparison in — the stored
     * DateTime, the serialized `H:i:s` string a nested fingerprint carries, the
     * DateTime {@see parse()} just built — to the clock time.
     *
     * The date part is deliberately dropped rather than compared: Craft fills it
     * from whatever day the value was normalised on, so two DateTimes standing
     * for the same 09:30 differ as instants while being the same stored value.
     * A value no time parse accepts keeps the base normal form, so non-times stay
     * distinguishable.
     */
    protected function normalize(mixed $value): mixed
    {
        $parsed = self::tryParse($value);

        return parent::normalize($parsed?->format(self::COMPARE_FORMAT) ?? $value);
    }
}
