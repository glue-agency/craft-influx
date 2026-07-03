<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\BaseOptionsField;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Option-based fields: Dropdown, RadioButtons, Checkboxes, MultiSelect.
 * Registered against `BaseOptionsField` so all four share this strategy.
 *
 * The one mapping option is `match` — which side of the Craft field's option
 * rows the feed value corresponds to (the same "Match by" vocabulary the
 * relational strategies use):
 *
 *   - `value` (default): the feed already sends stored option values —
 *     pass through unchanged.
 *   - `label`: the feed sends the human-readable labels ('A+', 'Te koop');
 *     each is translated to its option's stored value via a trimmed,
 *     case-insensitive lookup over the field's configured options.
 *
 * Unmatched values pass through unchanged either way — validating that the
 * result is part of the configured option set is Craft's job, not the
 * strategy's. Multi-value fields (Checkboxes, MultiSelect) receive arrays;
 * each element is translated individually.
 */
class Dropdown extends Field
{
    public static function craftFieldClass(): ?string
    {
        return BaseOptionsField::class;
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        return [
            BuilderSchema::select(
                'match',
                Craft::t('influx', 'Match by'),
                [
                    ['value' => 'value', 'label' => Craft::t('influx', 'Option value')],
                    ['value' => 'label', 'label' => Craft::t('influx', 'Option label')],
                ],
                ['default' => 'value'],
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        if ((string) $context->mapping->option('match', 'value') !== 'label') {
            return $raw;
        }

        $labelToValue = $this->labelToValueMap($context);

        if (is_array($raw)) {
            return array_map(
                fn(mixed $value): mixed => $this->translateLabel($value, $labelToValue),
                $raw,
            );
        }

        return $this->translateLabel($raw, $labelToValue);
    }

    /**
     * One feed value → stored option value, via the prebuilt label lookup.
     * Non-scalar and unmatched values pass through unchanged.
     *
     * @param array<string, mixed> $labelToValue
     */
    protected function translateLabel(mixed $value, array $labelToValue): mixed
    {
        if (! is_scalar($value)) {
            return $value;
        }

        return $labelToValue[mb_strtolower(trim((string) $value))] ?? $value;
    }

    /**
     * Lowercased/trimmed option label => stored option value for the Craft
     * field's configured options. Extracted so the no-boot tests can drive
     * parse() through an override without a real BaseOptionsField.
     *
     * @return array<string, mixed>
     */
    protected function labelToValueMap(FieldContext $context): array
    {
        $map = [];

        /** @var BaseOptionsField|null $field */
        $field = $context->craftField;

        foreach ($field?->options ?? [] as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $label = (string) ($opt['label'] ?? $opt['value']);
                $map[mb_strtolower(trim($label))] = $opt['value'];
            }
        }

        return $map;
    }
}
