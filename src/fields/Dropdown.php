<?php

namespace TDM\Influx\fields;

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
        return ['kind' => 'options', 'options' => $options];
    }

    public function parseField(): mixed
    {
        $raw = $this->fetchSimpleValue();
        if ($raw === null) {
            return null;
        }

        $map = $this->fieldInfo['options']['valueMap'] ?? null;
        if (is_array($map) && array_key_exists((string)$raw, $map)) {
            return $map[(string)$raw];
        }

        return $raw;
    }
}
