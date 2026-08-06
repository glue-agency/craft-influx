<?php

namespace GlueAgency\Influx\fields;

use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Range as CraftRangeField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Range (slider) mapping strategy (Craft 5).
 *
 * Craft neither clamps nor snaps a Range value — `normalizeValue()` just
 * normalises the number, and the field's validation rules cover its SETTINGS,
 * not its content — so on the DefaultField fallback a feed value off the scale
 * persisted happily. The damage shows up on the next round trip: the CP renders
 * an `<input type="range">`, which can only represent positions on the scale, so
 * an editor opening the entry and saving it snaps the value. The following sync
 * then sees a difference and writes the off-scale value back, and the field
 * ping-pongs between the two for as long as both keep touching it.
 *
 * Writing what the scale can actually hold ends that: {@see parse()} clamps to
 * the field's own min/max and snaps to its step, so Influx and the CP agree on
 * the value from the first sync.
 */
class Range extends Field
{
    public static function craftFieldClass(): ?string
    {
        return CraftRangeField::class;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed.
     *
     * @throws MappingValueException when a present value isn't numeric — a
     * slider can't hold a word, and silently writing null would look like the
     * feed cleared the field.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        if (! is_numeric($raw)) {
            $display = is_scalar($raw) ? (string) $raw : gettype($raw);

            throw new MappingValueException("Non-numeric range value '{$display}'.");
        }

        return $this->snap((float) $raw, $context->craftField);
    }

    /**
     * Clamp to the scale, then snap to the nearest step measured FROM THE MIN —
     * a scale running 10–100 in steps of 5 offers 10, 15, 20, not 15, 20, 25.
     * A non-positive step would make the snap a division by zero, so it degrades
     * to the clamped value.
     *
     * The result is cast back to int when the scale is a whole-number one, so an
     * integer slider doesn't store `20.0` and read as changed against the CP's
     * `20`.
     */
    protected function snap(float $value, ?CraftFieldInterface $field): int|float
    {
        [$min, $max, $step] = $this->scale($field);

        $value = max($min, min($max, $value));

        if ($step > 0) {
            $value = $min + (round(($value - $min) / $step) * $step);
            $value = max($min, min($max, $value));
        }

        $whole = floor($min) === $min && floor($step) === $step;

        return $whole ? (int) round($value) : $value;
    }

    /**
     * The field's own scale, defaulting to Craft's declared defaults when there's
     * no real field to read — the no-boot tests drive parse() without one.
     *
     * @return array{float, float, float}
     */
    protected function scale(?CraftFieldInterface $field): array
    {
        if (! $field instanceof CraftRangeField) {
            return [0.0, 100.0, 1.0];
        }

        return [(float) $field->min, (float) $field->max, (float) $field->step];
    }

    /**
     * A numeric value compares as a number, so `20`, `20.0` and `'20'` are one
     * value however each side of the comparison spells it.
     */
    protected function normalize(mixed $value): mixed
    {
        if (! is_numeric($value)) {
            return parent::normalize($value);
        }

        $number = (float) $value;

        return parent::normalize(floor($number) === $number ? (string) (int) $number : (string) $number);
    }
}
