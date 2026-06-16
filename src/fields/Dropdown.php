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
        return BaseOptionsField::class;
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        /** @var BaseOptionsField $field */
        $options = [];

        foreach ($field->options ?? [] as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $options[(string) $opt['value']] = (string) ($opt['label'] ?? $opt['value']);
            }
        }

        return [
            BuilderSchema::valueMapTable(
                'valueMap',
                Craft::t('influx', 'Value map'),
                $options,
                ['instructions' => Craft::t('influx', 'Remote → local value map. Leave empty rows to fall through.')],
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $map = $context->mapping->option('valueMap');

        if (is_array($map) && array_key_exists((string) $raw, $map)) {
            return $map[(string) $raw];
        }

        return $raw;
    }
}
