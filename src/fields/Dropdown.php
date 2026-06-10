<?php

namespace TDM\Influx\fields;

use TDM\Influx\sync\FieldContext;

/**
 * Option-based fields: Dropdown, RadioButtons, Checkboxes, MultiSelect.
 * Registered against `BaseOptionsField` so all four share this strategy.
 *
 * Supports an optional remote → local value map:
 *
 *   options.valueMap: { 'EN': 'english', 'NL': 'dutch' }
 *
 * Anything not in the map passes through unchanged; the underlying Craft
 * field validation is responsible for rejecting values that aren't part of
 * the configured option set.
 */
class Dropdown extends Field
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\BaseOptionsField::class;
    }

    public function fieldMeta(\craft\base\FieldInterface $field): array
    {
        /** @var \craft\fields\BaseOptionsField $field */
        $options = [];
        foreach ($field->options ?? [] as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $options[(string)$opt['value']] = (string)($opt['label'] ?? $opt['value']);
            }
        }
        return [
            'kind'    => 'options',
            'options' => $options,
            'labels'  => self::extrasLabels() + self::commonExtrasLabels(),
        ];
    }

    public function hasMappingExtras(): bool
    {
        return true;
    }

    /**
     * UI strings rendered inside the dropdown/value-map extras block.
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'valueMapHint' => \Craft::t('influx', 'Remote → local value map. Leave empty rows to fall through.'),
            'remoteValue'  => \Craft::t('influx', 'Remote value'),
            'pickLocal'    => \Craft::t('influx', '— pick —'),
            'addRow'       => \Craft::t('influx', 'Add value map'),
            'removeRow'    => \Craft::t('influx', 'Remove row'),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);
        if ($raw === null) {
            return null;
        }

        $map = $context->mapping->option('valueMap');
        if (is_array($map) && array_key_exists((string)$raw, $map)) {
            return $map[(string)$raw];
        }

        return $raw;
    }
}
