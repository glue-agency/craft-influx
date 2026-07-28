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
use GlueAgency\Influx\helpers\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use yii\base\Event;

/**
 * Date/time mapping strategy. Change detection needs no override of its own: the
 * shared normaliser ({@see \GlueAgency\Influx\helpers\Comparable::of()}) already
 * reduces a date to its timestamp, so two values for the same instant compare
 * equal and anything non-date compares as changed.
 */
class Date extends Field
{
    /**
     * Fires from {@see formatOptions()} so listeners can append site- or
     * feed-specific date formats (or replace the defaults wholesale).
     * Receives a {@see RegisterMappingOptionsEvent}.
     */
    public const EVENT_REGISTER_FORMAT_OPTIONS = 'registerFormatOptions';

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
     * custom Date field strategy itself — so the list lives in one place.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function formatOptions(): array
    {
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

        return $event->options;
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

        return DateTimeHelper::toDateTime($raw) ?: null;
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
}
