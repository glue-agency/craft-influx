<?php

namespace TDM\Influx\services;

use craft\base\Component;
use craft\base\FieldInterface as CraftFieldInterface;
use TDM\Influx\events\RegisterFieldsEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\fields\Assets;
use TDM\Influx\fields\Categories;
use TDM\Influx\fields\Date;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\fields\Dropdown;
use TDM\Influx\fields\Entries;
use TDM\Influx\fields\Field;
use TDM\Influx\fields\Lightswitch;
use TDM\Influx\fields\Matrix;
use TDM\Influx\fields\RichText;
use TDM\Influx\fields\Tags;
use TDM\Influx\fields\Users;

/**
 * Registry of per-Craft-field-type mapping strategies. Built-ins are seeded
 * into the registration event payload before triggering, so listeners can
 * append new strategies, override built-ins (by re-adding under the same
 * Craft field class), or remove them entirely.
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
class FieldsService extends Component
{
    public const EVENT_REGISTER_FIELDS = 'registerFields';

    /** Concrete Field strategy instances, keyed by Craft field FQCN. */
    protected array $byCraftFqcn = [];

    protected ?Field $default = null;

    protected bool $initialized = false;

    public function init(): void
    {
        parent::init();
        $this->default = new DefaultField();
    }

    /**
     * Built-ins shipped with the plugin. Exposed as a method so tests and
     * subclasses can override the default set.
     *
     * @return list<class-string<Field>>
     */
    protected function defaultFields(): array
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

    /**
     * Direct registration. Forces the registration event to fire first
     * (seeding built-ins) so callers can rely on overriding built-ins by
     * simply re-registering them — the explicit call always wins over the
     * defaults regardless of timing.
     *
     * @param class-string<Field> $class
     */
    public function registerClass(string $class): void
    {
        $this->ensureLoaded();
        $this->registerOne($class);
    }

    /**
     * Resolve the right strategy for the given Craft field. Walks the field's
     * class hierarchy so concrete Craft classes pick up a strategy registered
     * against their shared base.
     */
    public function forCraftField(CraftFieldInterface $field): Field
    {
        $this->ensureLoaded();

        for ($class = $field::class; $class; $class = get_parent_class($class)) {
            if (isset($this->byCraftFqcn[$class])) {
                return $this->byCraftFqcn[$class];
            }
        }
        return $this->default;
    }

    /**
     * UI metadata for a given Craft field — delegated to the matching
     * strategy so each field type owns both parse logic and UI hints.
     * Ships the declarative extras `schema` (rendered generically by the
     * SPA's SchemaForm) alongside the legacy kind/labels meta; whether a
     * field has an extras block follows from the schema's existence.
     */
    public function metaFor(CraftFieldInterface $field): array
    {
        $strategy = $this->forCraftField($field);

        return $strategy->fieldMeta($field) + [
            'schema' => $strategy->defineExtrasSchema($field),
        ];
    }

    /** @return array<class-string, Field> */
    public function all(): array
    {
        $this->ensureLoaded();
        return $this->byCraftFqcn;
    }

    protected function registerOne(string $class): void
    {
        if (!is_subclass_of($class, Field::class)) {
            throw new InfluxException("'{$class}' must extend " . Field::class . '.');
        }
        $fqcn = $class::craftFieldClass();
        if (!$fqcn) {
            // Subclass forgot to declare which Craft field it handles —
            // can't register without that, but it's not an error worth
            // breaking init for.
            return;
        }
        $this->byCraftFqcn[$fqcn] = new $class();
    }

    protected function ensureLoaded(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        $event = new RegisterFieldsEvent(['fields' => $this->defaultFields()]);
        $this->trigger(self::EVENT_REGISTER_FIELDS, $event);

        foreach ($event->fields as $class) {
            $this->registerOne($class);
        }
    }
}
