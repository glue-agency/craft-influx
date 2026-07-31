<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\events\RegisterFieldsEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\fields\RichText;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Users;

/**
 * Registry of per-Craft-field-type mapping strategies, keyed by the Craft field
 * FQCN each one declares. Built-ins are seeded into the registration event
 * payload before triggering, so listeners can append new strategies, override
 * built-ins (by re-adding under the same Craft field class), or remove them
 * entirely — see {@see AbstractRegistry} for the shared mechanics.
 *
 *   Event::on(
 *       FieldsService::class,
 *       FieldsService::EVENT_REGISTER_FIELDS,
 *       function (RegisterFieldsEvent $event) {
 *           $event->fields[] = MyMatrixStrategy::class;
 *       }
 *   );
 *
 * Lookups walk the parent class chain so concrete Craft classes (Dropdown,
 * RadioButtons, ...) pick up a strategy registered against their shared base
 * (BaseOptionsField, BaseRelationField). Unknown field types fall through to
 * {@see DefaultField}.
 */
class FieldsService extends AbstractRegistry
{
    public const EVENT_REGISTER_FIELDS = 'registerFields';

    /**
     * Fallback strategy for Craft field types no registered strategy claims.
     * Not a registered item: it declares no Craft field class, so there's no
     * key to file it under.
     */
    protected ?Field $default = null;

    public function init(): void
    {
        parent::init();
        $this->default = Craft::createObject(DefaultField::class);
    }

    /**
     * Resolve the right strategy for the given Craft field. Walks the field's
     * class hierarchy so concrete Craft classes pick up a strategy registered
     * against their shared base.
     */
    public function forCraftField(CraftFieldInterface $field): Field
    {
        for ($class = $field::class; $class; $class = get_parent_class($class)) {
            $strategy = $this->item($class);

            if ($strategy) {
                return $strategy;
            }
        }

        return $this->default;
    }

    /**
     * UI metadata for a given Craft field. A strategy declares its mapping
     * extras via a {@see Field::schema()} builder — the contract the SPA's
     * SchemaForm renders generically, with each control's label co-located on
     * its node — which {@see Field::meta()} wraps in the `{schema}` envelope
     * (whether a field has an extras block follows from the schema being
     * non-empty). A strategy's {@see Field::fieldMeta()} hook may contribute
     * extra keys but can't override `schema`.
     */
    public function metaFor(CraftFieldInterface $field): array
    {
        $strategy = $this->forCraftField($field);

        return Field::meta($strategy->schema($field)->toArray(), $strategy->fieldMeta($field));
    }

    /**
     * Default-value editor descriptor for a given Craft field, as
     * {@see \GlueAgency\Influx\schema\MappableField::custom()} consumes it:
     * `type` plus `options` / `elementType` when the kind needs them. An empty
     * array means "plain text input" — the strategy declared no opinion.
     *
     * @return array{type?: string, options?: array<string, string>, elementType?: class-string}
     */
    public function defaultEditorFor(CraftFieldInterface $field): array
    {
        return $this->forCraftField($field)->defaultEditor($field) ?? [];
    }

    /**
     * @return list<class-string<Field>>
     */
    protected function defaults(): array
    {
        return [
            Assets::class,
            Date::class,
            Lightswitch::class,
            Dropdown::class,
            Entries::class,
            RichText::class,
            Users::class,
            Categories::class,
            Tags::class,
            Matrix::class,
        ];
    }

    protected function itemType(): string
    {
        return Field::class;
    }

    /**
     * @throws InfluxException when the strategy declares no Craft field class:
     * only the {@see DefaultField} fallback may do that, and it's held apart
     * rather than registered.
     */
    protected function keyFor(object $item): string
    {
        return $item::craftFieldClass()
            ?? throw new InfluxException($item::class . ' must declare a craftFieldClass() to be registered.');
    }

    protected function eventName(): string
    {
        return self::EVENT_REGISTER_FIELDS;
    }

    protected function eventClass(): string
    {
        return RegisterFieldsEvent::class;
    }
}
