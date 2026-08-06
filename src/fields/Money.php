<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Money as CraftMoneyField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money as MoneyLibrary;
use Money\Parser\DecimalMoneyParser;
use Throwable;

/**
 * Money mapping strategy.
 *
 * On the DefaultField fallback this field rewrote itself on every sync. Craft
 * normalizes a Money value to a `Money\Money`
 * ({@see \craft\fields\Money::normalizeValue()}), which the shared comparison
 * normaliser can only reduce through `json_encode` — `Money\Money` is
 * JsonSerializable and not Stringable — so the stored side read as
 * `{"amount":"1999","currency":"EUR"}` while the feed's side was still the plain
 * `"19.99"` it shipped. Those never match, so every run re-saved the element and
 * cut a revision. {@see normalize()} reduces both to the minor-unit amount, which
 * is also what Craft serializes ({@see \craft\fields\Money::serializeValue()}
 * returns `getAmount()`), so the same reduction works inside a nested
 * fingerprint.
 *
 * The second problem was silent misreading. Craft treats a bare integer STRING
 * as minor units and a string with a decimal point as a major-unit amount, so a
 * feed shipping `1999` for €19.99 and one shipping `19.99` both "worked" — and a
 * feed shipping `2000` for €20.00 quietly became €20.00 while `20` became €0.20.
 * The `units` option makes that explicit instead of inferring it from
 * punctuation.
 *
 * Major-unit amounts are parsed with moneyphp's DECIMAL parser, not Craft's
 * {@see \craft\helpers\MoneyHelper::normalizeString()}: that one runs an
 * `IntlMoneyParser` bound to the site's formatting locale, which on a Dutch or
 * German site reads `19.99` as nineteen-thousand-nine-hundred-ninety. A feed is
 * machine data and its decimal point means a decimal point.
 */
class Money extends Field
{
    /** The mapping option naming which unit the feed's amounts are in. */
    protected const UNITS_OPTION = 'units';

    /** A decimal amount as a person writes it — `19.99` is nineteen euros ninety-nine. */
    protected const UNITS_MAJOR = 'major';

    /** The currency's smallest unit — `1999` is nineteen euros ninety-nine. */
    protected const UNITS_MINOR = 'minor';

    /** Craft's own default when a field declares no currency. */
    protected const DEFAULT_CURRENCY = 'USD';

    public static function craftFieldClass(): ?string
    {
        return CraftMoneyField::class;
    }

    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => true,
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->select([
                'handle'  => self::UNITS_OPTION,
                'label'   => Craft::t('influx', 'Amounts are in'),
                'options' => [
                    ['value' => self::UNITS_MAJOR, 'label' => Craft::t('influx', 'Major units (19.99)')],
                    ['value' => self::UNITS_MINOR, 'label' => Craft::t('influx', 'Minor units (1999)')],
                ],
                'default' => self::UNITS_MAJOR,
            ]),
        ]);
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed. A `Money\Money` is returned rather than a string so Craft has
     * nothing left to infer — {@see \craft\fields\Money::normalizeValue()} passes
     * one straight through.
     *
     * @throws MappingValueException when a present value isn't an amount. Silently
     * writing null would read as "the feed cleared the price", which is a very
     * different thing from "the feed sent something unparseable".
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        if (! is_scalar($raw)) {
            throw new MappingValueException(sprintf('Unparseable money value of type %s.', gettype($raw)));
        }

        $currency = new Currency($this->currencyCode($context->craftField));
        $minor = (string) $context->mapping->option(self::UNITS_OPTION, self::UNITS_MAJOR) === self::UNITS_MINOR;
        $amount = $minor ? trim((string) $raw) : $this->decimalAmount($raw);

        // The decimal parser reads an empty string as zero rather than refusing
        // it, so a value that cleaned down to no digits at all has to be caught
        // here — "free" becoming €0.00 is a silent wrong answer.
        if (preg_match($minor ? '/^-?\d+$/' : '/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new MappingValueException("Unparseable money value '{$raw}'.");
        }

        try {
            return $minor
                ? new MoneyLibrary($amount, $currency)
                : (new DecimalMoneyParser(new ISOCurrencies()))->parse($amount, $currency);
        } catch (Throwable $e) {
            throw new MappingValueException("Unparseable money value '{$raw}': {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * A major-unit amount as a plain decimal string the DECIMAL parser accepts.
     *
     * Feeds wrap amounts in currency symbols and thousands separators, so those
     * are stripped: with both separators present the rightmost is the decimal one
     * (`1.234,56` and `1,234.56` are the same amount), a lone comma followed by
     * one or two digits is a decimal comma, and a lone comma anywhere else groups
     * thousands. A lone dot is left alone — that's the machine convention and the
     * only reading that doesn't surprise.
     */
    protected function decimalAmount(mixed $raw): string
    {
        $value = preg_replace('/[^\d,.\-]/', '', (string) $raw) ?? '';
        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $thousands = $lastDot > $lastComma ? ',' : '.';
            $decimal = $lastDot > $lastComma ? '.' : ',';

            return str_replace($decimal, '.', str_replace($thousands, '', $value));
        }

        if ($lastComma !== false) {
            return preg_match('/,\d{1,2}$/', $value) === 1
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        }

        return $value;
    }

    /**
     * The field's own currency — a per-field setting, never per value, which is
     * why {@see normalize()} can compare amounts alone. Falls back to Craft's own
     * default when there's no real field to read (the no-boot tests).
     */
    protected function currencyCode(?CraftFieldInterface $field): string
    {
        if (! $field instanceof CraftMoneyField || $field->currency === '') {
            return self::DEFAULT_CURRENCY;
        }

        return $field->currency;
    }

    /**
     * Reduce both sides to the minor-unit amount: a stored `Money\Money` at the
     * top level, the amount string Craft serializes to inside a nested
     * fingerprint, and the `Money\Money` {@see parse()} just built. The currency
     * is deliberately left out — it's a field setting, so it cannot differ
     * between the two sides of one comparison.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof MoneyLibrary) {
            $value = $value->getAmount();
        }

        return parent::normalize($value);
    }
}
