<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\events\RegisterFieldsEvent;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\fields\Addresses;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Color;
use GlueAgency\Influx\fields\ContentBlock;
use GlueAgency\Influx\fields\Country;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Icon;
use GlueAgency\Influx\fields\Json;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Link;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\fields\Money;
use GlueAgency\Influx\fields\Range;
use GlueAgency\Influx\fields\RichText;
use GlueAgency\Influx\fields\Table;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Time;
use GlueAgency\Influx\fields\Users;
use GlueAgency\Influx\integrations\preparse\PreparseField;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;

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

    /**
     * Whether a nested row's extras are being built right now — the guard that
     * bounds {@see childRowFor()} at one card level.
     */
    protected bool $nesting = false;

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
     * A field's whole mapping row, as its strategy declares it. Regions resolve on
     * first ask, so asking for one costs one.
     */
    public function rowFor(CraftFieldInterface $field): MappingSchema
    {
        return $this->forCraftField($field)->schema($field);
    }

    /**
     * The row a NESTED field renders — a Matrix block's child, a related element's
     * sub-field, a field's own part: the control its default cell declares, plus
     * the extras it declares, so a nested field is configured the way the same
     * field is at the top level. A nested Assets row gets its `mode` / `upload` /
     * `conflict` and its own alt/title card; a nested Date its format; a nested
     * relation its match-by and its sub-fields.
     *
     * Everything here is honoured at sync time already: a sub-row is a whole
     * {@see \GlueAgency\Influx\models\FieldMapping}, and the applier descends into
     * the child's own strategy with it ({@see Field::coerceChildValue()},
     * {@see \GlueAgency\Influx\sync\item\MappingApplier::applySubMappings()}), so
     * the child reads its own `options` and applies its own sub-mappings.
     *
     * ONE card level, and that bound is load-bearing rather than a UI preference.
     * A relation graph can be cyclic — A relates B, B relates back to A — so
     * building a nested card's children's cards in turn would not terminate. The
     * re-entrancy guard cuts it at the fetch rather than after the fact, because by
     * the time a built region could be filtered the recursion has already happened.
     *
     * `matrixFields` never nests either way: a block container inside a block is
     * the one shape this deliberately doesn't offer.
     *
     * @return array{default: array|null, extra: list<array>}
     */
    public function childRowFor(CraftFieldInterface $field): array
    {
        $row = $this->rowFor($field);
        $cell = $row->region('default')[0] ?? null;

        if ($this->nesting) {
            return ['default' => $cell, 'extra' => []];
        }

        $this->nesting = true;

        try {
            $extra = array_values(array_filter(
                $row->region('extra'),
                static fn(array $node): bool => ($node['type'] ?? null) !== MappingSchemaBuilder::MATRIX_FIELDS,
            ));
        } finally {
            $this->nesting = false;
        }

        return ['default' => $cell, 'extra' => $extra];
    }

    /**
     * The options a field's default select offers, for a strategy that declared
     * its editor `lazy` and so shipped none with the descriptor
     * ({@see Field::defaultOptions()}).
     *
     * @return array<string, string> value => label
     */
    public function defaultOptionsFor(CraftFieldInterface $field): array
    {
        return $this->forCraftField($field)->defaultOptions($field);
    }

    /**
     * @return list<class-string<Field>>
     */
    protected function defaults(): array
    {
        return [
            Assets::class,
            Date::class,
            Time::class,
            Lightswitch::class,
            Dropdown::class,
            Entries::class,
            RichText::class,
            Users::class,
            Categories::class,
            Tags::class,
            Matrix::class,
            Table::class,
            Color::class,
            Country::class,
            Money::class,
            // Third-party, so it lives under integrations/ — and keyed by class
            // string, so an install without the plugin never hits the key.
            PreparseField::class,
            // Craft 5 only. The registry files a strategy under the class STRING
            // its craftFieldClass() names and matches by walking a real field's
            // parent chain, so on Craft 4 these are keys nothing ever hits — no
            // version branch needed. (`Link` doubles as the Craft 5 URL field,
            // which is a class_alias onto it; Craft 4's real Url field is a
            // string field the fallback already serves.)
            ContentBlock::class,
            Link::class,
            Addresses::class,
            Icon::class,
            Json::class,
            Range::class,
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
