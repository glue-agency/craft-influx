<?php

namespace GlueAgency\Influx\fields;

use CommerceGuys\Addressing\Country\Country as CountryModel;
use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Country as CraftCountryField;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Country mapping strategy.
 *
 * Craft's Country field advertises `phpType(): 'string|null'` and stores an
 * alpha-2 code, but {@see \craft\fields\Country::normalizeValue()} hands back a
 * `CommerceGuys\Addressing\Country\Country` MODEL. The shared comparison
 * normaliser can't reduce one — it isn't scalar, Stringable, a date or an element
 * — so it fell through to `json_encode`, and the stored side read as a JSON blob
 * of the whole model against the feed's `"BE"`. On the DefaultField fallback that
 * meant the field rewrote itself on every sync.
 *
 * Feeds also disagree about case (`be`, `BE`) and about whether they ship a code
 * at all. So {@see normalize()} reduces both sides to the uppercase alpha-2, and
 * the `match` option — the same "Match by" vocabulary the relational and option
 * strategies use — lets a feed that carries country NAMES resolve them through
 * Craft's own country repository instead of forcing the operator to remap the
 * feed.
 *
 * Unmatched values pass through unchanged: validating that a code exists is
 * Craft's job ({@see \craft\fields\Country::normalizeValue()} nulls an unknown
 * one), not the strategy's.
 */
class Country extends Field
{
    /** The feed ships alpha-2 codes — the default, and what Craft stores. */
    protected const MATCH_CODE = 'code';

    /** The feed ships country names, resolved through Craft's country repository. */
    protected const MATCH_NAME = 'name';

    public static function craftFieldClass(): ?string
    {
        return CraftCountryField::class;
    }

    /**
     * The default is one of the world's countries, so the row offers them as a
     * searchable select instead of a free-text box the operator could mistype an
     * alpha-2 into — the same closed-set reasoning {@see Dropdown} applies to a
     * field's own options.
     *
     * That select is LAZY, and so carries no options: there are around 250 of
     * them, and an eager list would ride every builder bootstrap once per Country
     * field on the layout whether or not the operator ever opens that row.
     * {@see defaultOptions()} answers the fetch.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => true,
            'default' => fn(MappingSchemaBuilder $b) => $b->defaultSelect(['lazy' => true]),
            'extra'   => fn(MappingSchemaBuilder $b)   => $b->matchBy([
                'options' => [
                    ['value' => self::MATCH_CODE, 'label' => Craft::t('influx', 'Code (BE)')],
                    ['value' => self::MATCH_NAME, 'label' => Craft::t('influx', 'Name (Belgium)')],
                ],
                'default' => self::MATCH_CODE,
            ]),
        ]);
    }

    /**
     * Every country, alpha-2 => name, in the current language.
     *
     * @return array<string, string>
     */
    public function defaultOptions(CraftFieldInterface $field): array
    {
        $options = [];

        foreach ($this->countryList() as $code => $name) {
            $options[(string) $code] = (string) $name;
        }

        return $options;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null || ! is_scalar($raw)) {
            return $raw;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // A default is picked from the country list (see schema()), so it
        // already IS a stored alpha-2 — `match: name` describes what the FEED
        // sends, and would translate a picked code into nothing.
        if ($context->mapping->usesDefault($context->item)) {
            return strtoupper($value);
        }

        if ((string) $context->mapping->option('match', self::MATCH_CODE) !== self::MATCH_NAME) {
            return strtoupper($value);
        }

        return $this->nameToCodeMap()[mb_strtolower($value)] ?? $value;
    }

    /**
     * Lowercased country name => alpha-2, for the `match: name` lookup.
     *
     * @return array<string, string>
     */
    protected function nameToCodeMap(): array
    {
        $map = [];

        foreach ($this->countryList() as $code => $name) {
            $map[mb_strtolower(trim((string) $name))] = (string) $code;
        }

        return $map;
    }

    /**
     * Alpha-2 => country name, from Craft's own repository in the current
     * language. Read straight off the repository rather than through
     * `Addresses::getCountryList()`: that wrapper is Craft 5.5+, and a site that
     * narrows its country list shouldn't stop a feed resolving a country Craft
     * itself would still accept ({@see \craft\fields\Country::normalizeValue()}
     * goes to the repository too).
     *
     * THE seam onto Craft here, so the no-boot tests can drive both the name
     * lookup and the default select through one override.
     *
     * @return array<string, string>
     */
    protected function countryList(): array
    {
        return Craft::$app->getAddresses()->getCountryRepository()->getList(Craft::$app->language);
    }

    /**
     * Reduce every shape a country reaches a comparison in — the stored
     * {@see CountryModel}, the alpha-2 string Craft serializes to, the string
     * {@see parse()} just built — to the uppercase code.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof CountryModel) {
            $value = $value->getCountryCode();
        }

        if (is_scalar($value)) {
            $value = strtoupper(trim((string) $value));
        }

        return parent::normalize($value);
    }
}
