<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\fields\Date as DateField;
use craft\helpers\DateTimeHelper;
use GlueAgency\Influx\events\RegisterMappingOptionsEvent;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\sync\FieldContext;
use yii\base\Event;

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
        return DateField::class;
    }

    /**
     * UI strings rendered inside the date extras block.
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'formatLabel' => Craft::t('influx', 'Date format'),
            'formatHint'  => Craft::t('influx', 'Used by DateTime::createFromFormat. "Unix timestamp" parses integer seconds; "Auto-detect" uses the Craft DateTimeHelper.'),
        ];
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

    public function defineExtrasSchema(\craft\base\FieldInterface $field): array
    {
        return [
            \GlueAgency\Influx\helpers\BuilderSchema::select('format', Craft::t('influx', 'Date format'), self::formatOptions(), [
                'instructions' => Craft::t('influx', 'Used by DateTime::createFromFormat. "Unix timestamp" parses integer seconds; "Auto-detect" uses the Craft DateTimeHelper.'),
                'default'      => '',
            ]),
        ];
    }

    /**
     * @throws MappingValueException when a present value can't be parsed as
     * a date — malformed data must surface as an error row, not silently
     * leave the field untouched.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);
        if ($raw === null || $raw === '') {
            return null;
        }

        $parsed = DateTimeHelper::toDateTime($raw);
        if ($parsed === false) {
            $display = is_scalar($raw) ? (string)$raw : gettype($raw);
            throw new MappingValueException("Unparseable date value '{$display}'.");
        }
        return $parsed;
    }

    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        if (!$incoming instanceof \DateTimeInterface) {
            return parent::hasChanged($context, $incoming);
        }
        try {
            $current = $context->element->getFieldValue($context->handle);
        } catch (\Throwable) {
            return true;
        }
        if (!$current instanceof \DateTimeInterface) {
            return true;
        }
        return $current->getTimestamp() !== $incoming->getTimestamp();
    }
}
