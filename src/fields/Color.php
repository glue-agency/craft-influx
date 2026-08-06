<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Color as CraftColorField;
use craft\fields\data\ColorData;
use craft\validators\ColorValidator;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Colour mapping strategy.
 *
 * A colour has more than one spelling for the same value — `#E53935`, `#e53935`
 * and `e53935` are one colour — and the DefaultField fallback compared the feed's
 * spelling against the stored one verbatim. Craft normalizes on the way in
 * ({@see \craft\fields\Color::normalizeValue()} runs every value through
 * {@see ColorValidator::normalizeColor()}), so a feed shipping any spelling but
 * Craft's own rewrote the field on every sync.
 *
 * Both sides now go through Craft's own normaliser, so the comparison sees the
 * same canonical `#rrggbb` the field stores. Normalising in {@see parse()} as
 * well as {@see normalize()} is deliberate: it's what makes the value the
 * strategy writes identical to the value it will later read back.
 */
class Color extends Field
{
    /** What {@see ColorValidator::normalizeColor()} produces, plus the keyword it passes through. */
    protected const CANONICAL_PATTERN = '/^(#[0-9a-f]{6}|transparent)$/';

    public static function craftFieldClass(): ?string
    {
        return CraftColorField::class;
    }

    /**
     * A field with a palette has a closed set of colours, so the "use default"
     * cell offers them rather than a box to type a hex into — the same reasoning
     * {@see Dropdown::schema()} applies to a field's own options. Rows are
     * labelled the way the field labels them, falling back to the hex where a
     * palette entry carries no label.
     *
     * A field with NO palette keeps a text box: there's nothing to pick from, and
     * any hex is valid.
     *
     * Note that on a palette field with `allowCustomColors` on, this narrows the
     * DEFAULT to palette entries — an off-palette default has to be typed into the
     * feed instead. That's the trade for not retyping hexes, and it costs nothing
     * a feed can't do: {@see parse()} still accepts any colour it sends.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        $palette = $this->paletteOptions($field);

        return MappingSchemaBuilder::make()->mapping([
            'source' => true,
            // No palette, no closed set: a text box, since any hex is valid.
            'default' => $palette === []
                ? true
                : fn(MappingSchemaBuilder $b) => $b->defaultSelect(['options' => $palette]),
        ]);
    }

    /**
     * The field's palette as select rows, in its own order. A palette entry with
     * no label labels itself with its hex.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function paletteOptions(CraftFieldInterface $field): array
    {
        $options = [];

        /** @var CraftColorField $field */
        foreach ($field->palette ?? [] as $entry) {
            $hex = is_array($entry) ? self::tryNormalize($entry['color'] ?? null) : null;

            if ($hex === null) {
                continue;
            }

            $label = (string) ($entry['label'] ?? '');
            $options[] = ['value' => $hex, 'label' => $label !== '' ? Craft::t('site', $label) : $hex];
        }

        return $options;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed. A picked default needs no special case, unlike a
     * {@see Country}'s: the select's values are already the canonical hex this
     * produces, so normalising one again is a no-op.
     *
     * @throws MappingValueException when a present value isn't a colour — a
     * malformed one must surface as an error row rather than reach the field,
     * where Craft's normaliser would happily turn `red` into `#red`.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $hex = self::tryNormalize($raw);

        if ($hex === null) {
            $display = is_scalar($raw) ? (string) $raw : gettype($raw);

            throw new MappingValueException("Unparseable colour value '{$display}'.");
        }

        return $hex;
    }

    /**
     * A colour in its canonical form, or null when the value isn't one. Craft's
     * normaliser only reshapes — it prefixes a `#` and expands `#abc` — so the
     * result still has to be checked against what a colour actually looks like.
     */
    public static function tryNormalize(mixed $value): ?string
    {
        if ($value instanceof ColorData) {
            $value = $value->getHex();
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '#') {
            return null;
        }

        $hex = ColorValidator::normalizeColor($value);

        return preg_match(self::CANONICAL_PATTERN, $hex) === 1 ? $hex : null;
    }

    /**
     * Reduce every shape a colour reaches a comparison in — a stored
     * {@see ColorData}, the hex string a nested fingerprint carries, the string
     * {@see parse()} just built — to the same canonical hex. A value that isn't a
     * colour keeps the base normal form, so non-colours stay distinguishable.
     */
    protected function normalize(mixed $value): mixed
    {
        return parent::normalize(self::tryNormalize($value) ?? $value);
    }
}
