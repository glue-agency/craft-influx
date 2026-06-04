<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\events\RegisterComponentTypesEvent;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\fields\Assets;
use TDM\Influx\fields\Categories;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\fields\Dropdown;
use TDM\Influx\fields\Entries;
use TDM\Influx\fields\Field;
use TDM\Influx\fields\Lightswitch;
use TDM\Influx\fields\Matrix;
use TDM\Influx\fields\Tags;
use TDM\Influx\fields\Users;

/**
 * Registry of per-Craft-field-type mapping strategies. Keyed by Craft field
 * FQCN; lookups walk the parent class chain so {@see \craft\fields\Dropdown}
 * etc. resolve to the strategy registered against `BaseOptionsField`, and any
 * relation field resolves to the right Relation subclass via its concrete class.
 *
 * Third parties can add or override strategies via
 * {@see self::EVENT_REGISTER_FIELDS} or {@see registerClass()}.
 */
class FieldsService extends Component
{
    public const EVENT_REGISTER_FIELDS = 'registerFields';

    /** Concrete Field strategy instances, keyed by Craft field FQCN. */
    private array $byCraftFqcn = [];

    private ?Field $default = null;

    private bool $initialized = false;

    public function init(): void
    {
        parent::init();

        $this->registerClass(Assets::class);
        $this->registerClass(Lightswitch::class);
        $this->registerClass(Dropdown::class);
        $this->registerClass(Entries::class);
        $this->registerClass(Users::class);
        $this->registerClass(Categories::class);
        $this->registerClass(Tags::class);
        $this->registerClass(Matrix::class);

        $this->default = Craft::createObject(DefaultField::class);
    }

    /**
     * UI metadata for a given Craft field — delegated to the matching
     * strategy so each field type owns both parse logic and UI hints.
     * Returns an empty array for field types nothing has an opinion on.
     */
    public function metaFor(CraftFieldInterface $field): array
    {
        return $this->forCraftField($field)->fieldMeta($field);
    }

    /**
     * @param class-string<Field> $class
     */
    public function registerClass(string $class): void
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
        $this->byCraftFqcn[$fqcn] = Craft::createObject($class);
    }

    /**
     * Resolve the right strategy for the given Craft field. Walks the field's
     * class hierarchy so concrete Craft classes (Dropdown, RadioButtons, ...)
     * pick up a strategy registered against their shared base
     * (BaseOptionsField, BaseRelationField).
     */
    public function forCraftField(CraftFieldInterface $field): Field
    {
        $this->ensureExternalsRegistered();

        for ($class = $field::class; $class; $class = get_parent_class($class)) {
            if (isset($this->byCraftFqcn[$class])) {
                return $this->byCraftFqcn[$class];
            }
        }
        return $this->default;
    }

    /** @return array<class-string, Field> */
    public function all(): array
    {
        $this->ensureExternalsRegistered();
        return $this->byCraftFqcn;
    }

    private function ensureExternalsRegistered(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        if ($this->hasEventHandlers(self::EVENT_REGISTER_FIELDS)) {
            $event = new RegisterComponentTypesEvent();
            $this->trigger(self::EVENT_REGISTER_FIELDS, $event);
            foreach ($event->types as $class) {
                $this->registerClass($class);
            }
        }
    }
}
