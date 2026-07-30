<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Date as CraftDateField;
use craft\helpers\DateTimeHelper;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use GlueAgency\Influx\events\RegisterMappingOptionsEvent;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use yii\base\Event;

/**
 * Date/time mapping strategy.
 *
 * Change detection reduces a date to its instant — but only the shared
 * normaliser's DateTime case does that, and a STORED date doesn't always reach a
 * comparison as a DateTime: Craft serializes it to a string first. Widening that
 * normalisation to bring a serialized date back to a DateTime is this strategy's
 * one comparison concern ({@see normalize()}).
 */
class Date extends Field
{
    /**
     * Fires from {@see formatOptions()} so listeners can append site- or
     * feed-specific date formats (or replace the defaults wholesale).
     * Receives a {@see RegisterMappingOptionsEvent}.
     */
    public const EVENT_REGISTER_FORMAT_OPTIONS = 'registerFormatOptions';

    /**
     * Resolved option list, memoized for the rest of the request. Every builder
     * bootstrap asks for it several times over (this strategy's schema plus each
     * native date attribute a target declares), and re-firing the event each
     * time would run third-party listeners — which may query — for an answer
     * that can't have changed: the listener set is fixed once a request is
     * under way, and a request renders in one language.
     *
     * @var list<array{value: string, label: string}>|null
     */
    protected static ?array $formatOptions = null;

    public static function craftFieldClass(): ?string
    {
        return CraftDateField::class;
    }

    /**
     * Preset PHP date formats offered in the mapping UI. Empty value means
     * "no explicit format" — the server then falls through to Craft's
     * `DateTimeHelper::toDateTime`. `timestamp` is a UI sentinel that the
     * server translates to the `U` token so the dropdown stays readable.
     *
     * Shared by every code path that builds `kind: date` field meta — the
     * native postDate/expiryDate entries on {@see EntryTarget} and the
     * custom Date field strategy itself — so the list lives in one place, and
     * {@see $formatOptions} resolves it once per request.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function formatOptions(): array
    {
        if (static::$formatOptions !== null) {
            return static::$formatOptions;
        }

        $event = new RegisterMappingOptionsEvent([
            'options' => [
                ['value' => '',               'label' => Craft::t('influx', 'Auto-detect')],
                ['value' => 'timestamp',      'label' => Craft::t('influx', 'Unix timestamp (seconds)')],
                ['value' => 'c',              'label' => Craft::t('influx', 'ISO 8601 (c)')],
                ['value' => 'r',              'label' => Craft::t('influx', 'RFC 2822 (r)')],
                ['value' => 'Y-m-d H:i:s',    'label' => Craft::t('influx', 'Datetime (Y-m-d H:i:s)')],
                ['value' => "Y-m-d\\TH:i:sP", 'label' => Craft::t('influx', 'Datetime with tz (Y-m-d\\TH:i:sP)')],
                ['value' => 'Y-m-d',          'label' => Craft::t('influx', 'Date (Y-m-d)')],
                ['value' => 'd/m/Y',          'label' => Craft::t('influx', 'Date EU (d/m/Y)')],
                ['value' => 'm/d/Y',          'label' => Craft::t('influx', 'Date US (m/d/Y)')],
                ['value' => 'd-m-Y',          'label' => Craft::t('influx', 'Date (d-m-Y)')],
            ],
        ]);
        Event::trigger(self::class, self::EVENT_REGISTER_FORMAT_OPTIONS, $event);

        return static::$formatOptions = $event->options;
    }

    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        return SchemaBuilder::make()
            ->dateFormat(['options' => self::formatOptions()]);
    }

    /**
     * THE date-parsing rule, shared with the native date attributes
     * ({@see \GlueAgency\Influx\targets\EntryTarget::assignDate()} applies the
     * same mapping option to postDate / expiryDate). An explicit `format`
     * option wins over the auto-detector — feeds that ship ambiguous strings
     * (e.g. `02/03/2024`) need to disambiguate manually — and `timestamp` is a UI
     * sentinel for Unix seconds, translated to the PHP `U` token here so the Vue
     * side stays human-readable. The source timezone defaults to UTC, but
     * `createFromFormat()`'s third argument is only a fallback: a format carrying
     * its own tz token still wins. A non-scalar value can't be format-parsed, so
     * it goes to the auto-detector, which understands Craft's array date shapes.
     *
     * The auto-detector reads a timezone-less value as SYSTEM time, not UTC: a
     * feed writing `2026-07-07` means midnight where the site lives, and reading
     * it as UTC shifts every date-only value by the site's offset — enough to
     * rewrite them on every sync. A value carrying its own offset is unaffected,
     * so this stays an assumption for the ambiguous case rather than an override.
     *
     * Returns null when the value can't be parsed — what to DO about that is the
     * caller's policy, and the two callers differ deliberately.
     */
    public static function tryParse(mixed $raw, mixed $format = null): ?DateTime
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw instanceof DateTime ? $raw : DateTime::createFromInterface($raw);
        }

        if (is_string($format) && $format !== '' && is_scalar($raw)) {
            $phpFormat = $format === 'timestamp' ? 'U' : $format;
            $parsed = DateTime::createFromFormat($phpFormat, (string) $raw, new DateTimeZone('UTC'));

            return $parsed === false ? null : $parsed;
        }

        return DateTimeHelper::toDateTime($raw, true) ?: null;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed.
     *
     * @throws MappingValueException when a present value can't be parsed as
     * a date — malformed data must surface as an error row, not silently
     * leave the field untouched.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $parsed = self::tryParse($raw, $context->mapping->option('format'));

        if ($parsed === null) {
            $display = is_scalar($raw) ? (string) $raw : gettype($raw);

            throw new MappingValueException("Unparseable date value '{$display}'.");
        }

        return $parsed;
    }

    /**
     * Bring a serialized date back to a DateTime before the shared normaliser
     * sees it, so the same instant compares equal whichever shape it arrives in.
     * Needed because {@see Matrix} fingerprints its stored blocks through
     * `getSerializedFieldValues()`, where Craft has already rendered the date as
     * a string ({@see Compat::serializedDateFormat()}) while the incoming side is
     * still a DateTime — string vs. timestamp never matches, so a date leaf read
     * as changed on every sync. The top-level path is unaffected: there the
     * stored value is the field's normalized DateTime, which {@see tryParse()}
     * hands straight back.
     *
     * Pre-Craft-4.15 dates with "Show time zone" on serialize as `{date, tz}`
     * instead: `date` is already UTC and `tz` is display-only, so `date` alone
     * carries the instant. A value no date parse accepts keeps the base normal
     * form rather than collapsing to null, so non-dates stay distinguishable.
     */
    protected function normalize(mixed $value): mixed
    {
        if (is_array($value) && ! empty($value['date'])) {
            $value = $value['date'];
        }

        return parent::normalize(self::tryParse($value, Compat::serializedDateFormat()) ?? $value);
    }
}
