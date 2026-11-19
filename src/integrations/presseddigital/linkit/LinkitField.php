<?php

namespace GlueAgency\Influx\integrations\presseddigital\linkit;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for Pressed Digital's Linkit field.
 *
 * Linkit stores a link as a type, the value that type addresses, and optionally
 * a custom text and a new-tab flag — the same shape as Craft's own Link field,
 * so the row is the same: node-less, one sub-mapping per slot
 * ({@see \GlueAgency\Influx\fields\Link}). Which slots exist is the field's
 * business: the type select offers the link types the field enables, custom
 * text appears only with `allowCustomText`, the new-tab row only with
 * `allowTarget`.
 *
 * A type is Linkit's model class. A feed may also name it the way Feed Me's
 * Linkit importer accepts — `url`, `email`, `entry` — which resolves to the
 * class of that name in Linkit's own models.
 *
 * Element-typed links (entry, asset, category, user, product) take the
 * element's ID as their value, the same v1 boundary as the Link strategy.
 *
 * Keyed by class string, so an install without the plugin never touches it.
 */
class LinkitField extends Field
{
    protected const TYPE_HANDLE = 'type';

    protected const VALUE_HANDLE = 'value';

    protected const CUSTOM_TEXT_HANDLE = 'customText';

    protected const TARGET_HANDLE = 'target';

    /** Where a short type name (`url`) resolves to a class. */
    protected const MODELS_NAMESPACE = 'presseddigital\linkit\models\\';

    public static function craftFieldClass(): ?string
    {
        return 'presseddigital\linkit\fields\LinkitField';
    }

    public static function matchable(): bool
    {
        return false;
    }

    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => false,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) use ($field) {
                $types = $this->enabledTypes($field);

                $subFields = MappingSchemaBuilder::make()
                    ->select([
                        'handle'  => self::TYPE_HANDLE,
                        'label'   => Craft::t('influx', 'Link type'),
                        'options' => array_map(
                            static fn(string $class, string $label): array => ['value' => $class, 'label' => $label],
                            array_keys($types),
                            $types,
                        ),
                        'default' => array_key_first($types) ?? '',
                    ])
                    ->text([
                        'handle' => self::VALUE_HANDLE,
                        'label'  => Craft::t('influx', 'Value'),
                    ]);

                if ($this->allowsCustomText($field)) {
                    $subFields->text([
                        'handle' => self::CUSTOM_TEXT_HANDLE,
                        'label'  => Craft::t('influx', 'Custom text'),
                    ]);
                }

                if ($this->allowsTarget($field)) {
                    $subFields->lightswitch([
                        'handle' => self::TARGET_HANDLE,
                        'label'  => Craft::t('influx', 'Open in new window'),
                    ]);
                }

                return $b->subFields([
                    'label'     => Craft::t('influx', 'Link'),
                    'subFields' => $subFields->toArray(),
                ]);
            },
        ]);
    }

    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value Linkit's `normalizeValue()` takes. A link with no value is no
     * link, so it clears the field, as Linkit itself does with an empty value.
     *
     * @throws MappingValueException when the feed names a type the field doesn't
     * enable. Linkit instantiates whatever class it is handed, so an unknown one
     * would fail the whole item rather than this row.
     *
     * @return array<string, mixed>|null
     */
    public function parse(FieldContext $context): mixed
    {
        $allowed = $this->mappableHandles($context->craftField);
        $values = [];

        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if (in_array($sub->handle, $allowed, true)) {
                $values[$sub->handle] = $sub->resolve($context->item);
            }
        }

        $value = $values[self::VALUE_HANDLE] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return null;
        }

        $link = [
            self::TYPE_HANDLE  => $this->resolveType($context->craftField, $values[self::TYPE_HANDLE] ?? null),
            self::VALUE_HANDLE => $value,
        ];

        if (isset($values[self::CUSTOM_TEXT_HANDLE]) && is_scalar($values[self::CUSTOM_TEXT_HANDLE])) {
            $link[self::CUSTOM_TEXT_HANDLE] = (string) $values[self::CUSTOM_TEXT_HANDLE];
        }

        if (array_key_exists(self::TARGET_HANDLE, $values)) {
            $link[self::TARGET_HANDLE] = static::coerceTarget($values[self::TARGET_HANDLE]);
        }

        return $link;
    }

    /**
     * The new-tab flag. A feed speaking HTML sends a `target` attribute rather
     * than a boolean, so `_blank` and `_self` read as what they mean.
     */
    public static function coerceTarget(mixed $raw): bool
    {
        if (is_string($raw)) {
            $target = strtolower(trim($raw));

            if ($target === '_blank') {
                return true;
            }

            if ($target === '_self') {
                return false;
            }
        }

        return Lightswitch::coerce($raw);
    }

    /**
     * Both sides as `{type, value, customText, target}`: the stored side is a
     * Linkit link model, the incoming one what {@see parse()} built. Empty slots
     * drop out, so a link without custom text compares equal to one whose
     * custom text is null.
     */
    protected function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = [
                self::TYPE_HANDLE        => $value->type ?? null,
                self::VALUE_HANDLE       => $value->value ?? null,
                self::CUSTOM_TEXT_HANDLE => $value->customText ?? null,
                self::TARGET_HANDLE      => $value->target ?? null,
            ];
        }

        if (! is_array($value) || (string) ($value[self::VALUE_HANDLE] ?? '') === '') {
            return null;
        }

        $link = [
            self::TYPE_HANDLE        => ltrim((string) ($value[self::TYPE_HANDLE] ?? ''), '\\'),
            self::VALUE_HANDLE       => (string) $value[self::VALUE_HANDLE],
            self::CUSTOM_TEXT_HANDLE => (string) ($value[self::CUSTOM_TEXT_HANDLE] ?? ''),
            self::TARGET_HANDLE      => (bool) ($value[self::TARGET_HANDLE] ?? false),
        ];

        return array_filter($link, static fn(mixed $slot): bool => $slot !== '' && $slot !== false);
    }

    /**
     * The feed's type as a class the field enables, or the field's first enabled
     * type when the feed names none.
     *
     * @throws MappingValueException
     */
    protected function resolveType(?CraftFieldInterface $field, mixed $raw): string
    {
        $types = array_keys($this->enabledTypes($field));
        $type = is_scalar($raw) ? ltrim(trim((string) $raw), '\\') : '';

        if ($type === '') {
            return $types[0] ?? throw new MappingValueException('This Linkit field enables no link types.');
        }

        if (! str_contains($type, '\\')) {
            $type = self::MODELS_NAMESPACE . ucfirst(strtolower($type));
        }

        foreach ($types as $class) {
            if (strcasecmp($class, $type) === 0) {
                return $class;
            }
        }

        throw new MappingValueException("Unsupported link type '{$type}' for this field.");
    }

    /**
     * @return list<string>
     */
    protected function mappableHandles(?CraftFieldInterface $field): array
    {
        $handles = [self::TYPE_HANDLE, self::VALUE_HANDLE];

        if ($this->allowsCustomText($field)) {
            $handles[] = self::CUSTOM_TEXT_HANDLE;
        }

        if ($this->allowsTarget($field)) {
            $handles[] = self::TARGET_HANDLE;
        }

        return $handles;
    }

    /**
     * The field's enabled link types, class => label, in its own order.
     *
     * @return array<string, string>
     */
    protected function enabledTypes(?CraftFieldInterface $field): array
    {
        if ($field === null || ! method_exists($field, 'getEnabledLinkTypes')) {
            return [];
        }

        $types = [];

        foreach ($field->getEnabledLinkTypes() as $linkType) {
            $types[ltrim($linkType->type, '\\')] = (string) $linkType->label;
        }

        return $types;
    }

    protected function allowsCustomText(?CraftFieldInterface $field): bool
    {
        return (bool) ($field->allowCustomText ?? false);
    }

    protected function allowsTarget(?CraftFieldInterface $field): bool
    {
        return (bool) ($field->allowTarget ?? false);
    }

    /**
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $mapping): array
    {
        return $this->filterActive($mapping->subMappings());
    }
}
