<?php

namespace GlueAgency\Influx\schema;

use Closure;
use Craft;

/**
 * The mapping-only half of the form vocabulary.
 *
 * {@see SchemaBuilder} is the generic one: a flat list of nodes, which is all an
 * auth strategy's form is ({@see \GlueAgency\Influx\auth\AuthStrategyInterface::schema()})
 * — single fields, rendered stacked, with no concept of a mapping row, a source
 * node or a stored mapping slot. Everything here needs those concepts, so it
 * lives one level down rather than cluttering the vocabulary that doesn't.
 *
 * Field strategies and element targets build with this; auth strategies keep the
 * plain builder. `make()` is late-bound on the parent, so chaining from either
 * returns the class you asked for.
 */
class MappingSchemaBuilder extends SchemaBuilder
{
    /**
     * Sub-mapping container node types. Only a mapping row nests other rows, so
     * these live here rather than in the generic vocabulary.
     */
    public const SUB_FIELDS = 'subFields';
    public const ELEMENT_SUB_FIELDS = 'elementSubFields';
    public const MATRIX_FIELDS = 'matrixFields';

    /**
     * The source-node select's "use the default instead" row. A UI-only sentinel:
     * it round-trips to the mapping's `useDefault` flag, never to a stored `node`.
     * Declared here because both sides need the same string — {@see sourceNode()}
     * puts it on the wire, and the SPA's slot writer reads it back off the node's
     * `sentinel` map.
     */
    public const USE_DEFAULT = '__default__';

    /**
     * A whole mapping row's UI, as its three regions — the terminal call a field
     * strategy's {@see \GlueAgency\Influx\fields\Field::schema()} returns.
     *
     * Regions are a different shape from a flat node list, so this hands back a
     * {@see MappingSchema} rather than more of this builder.
     *
     * @param array<string, bool|Closure> $regions `source` / `default` / `extra`
     */
    public function mapping(array $regions): MappingSchema
    {
        return MappingSchema::make($regions);
    }

    /**
     * THE source-node select — the control `'source' => true` resolves to, and the
     * one every mapping row had hardcoded in Vue until now.
     *
     * Carries no placeholder on purpose: an unmapped row reads empty, like every
     * other empty field on it. The stand-in copy belongs in the option list, and
     * it's there — the two sentinel rows below lead the list, so a closed select
     * saying "— no mapping —" was only repeating what the open one says.
     *
     * The discovered feed nodes are NOT here. They come from the fetched sample,
     * which is client state, so the renderer merges them in beneath the sentinels
     * — under the heading `optionsLabel` names, so even that isn't a source-cell
     * special case in the renderer. This declares the rows that aren't feed nodes,
     * and how they map to slots.
     */
    public function sourceNode(array $config = []): static
    {
        return $this->select($config + [
            'allowCustom'       => true,
            'searchable'        => true,
            'searchPlaceholder' => Craft::t('influx', 'Search nodes…'),
            'emptyLabel'        => Craft::t('influx', 'Run “Fetch sample” to discover nodes.'),
            'sentinelOptions'   => [
                ['value' => '', 'label' => Craft::t('influx', '— no mapping —')],
                ['value' => self::USE_DEFAULT, 'label' => Craft::t('influx', '— use default —')],
            ],
            // How to head the group the renderer merges the sample's nodes into.
            'optionsLabel' => Craft::t('influx', 'Nodes'),
            'optionsKind'  => 'node',
            // One control, two slots: picking `__default__` sets the mapping's
            // `useDefault` flag instead of its `node`. The empty row needs no
            // flag — an empty slot is pruned away.
            'sentinel' => [self::USE_DEFAULT => 'useDefault'],
        ]);
    }

    /**
     * A default cell's select — the control a strategy declares when the field's
     * default is one of a closed set the FIELD owns: an option field's own
     * options, a colour palette, the world's countries.
     *
     * Carries the cell's own ergonomics so no strategy has to remember them: the
     * search box (a default list can run to a country's worth of rows) and the
     * "nothing picked" row that keeps a picked default clearable. NOT
     * `allowCustom`, unlike {@see sourceNode()} — a node path is open-ended, a
     * default is a value the field itself declares.
     *
     * That row is a SENTINEL, declared exactly as the source cell declares its own
     * two, and not an option merged into the field's list. Which is the whole
     * difference: it sits in its own group above the values rather than posing as
     * one of them, it drops out of the list while the operator is searching, and
     * picking it leaves the cell reading empty instead of announcing "—". An
     * unset default now looks like every other empty field on the row, the same
     * call {@see sourceNode()} makes.
     *
     * Works for a `lazy` list too, since a sentinel rides the node while the
     * options ride the fetch — so the endpoint answering that fetch
     * ({@see \GlueAgency\Influx\services\LinkBuilderService::defaultOptionsFor()})
     * returns the field's values and nothing else.
     */
    public function defaultSelect(array $config = []): static
    {
        return $this->select($config + [
            'searchable'        => true,
            'searchPlaceholder' => Craft::t('influx', 'Search options…'),
            'sentinelOptions'   => [
                ['value' => '', 'label' => Craft::t('influx', '— no default —')],
            ],
        ]);
    }

    /**
     * The multi-valued flavour, for the option fields that hold a LIST
     * (Checkboxes, MultiSelect) — where a single-value picker could only ever set
     * one of the boxes.
     *
     * No blank row: a multi picker expresses "none" by having nothing selected, so
     * one would be a no-op the operator can click.
     */
    public function defaultMultiSelect(array $config = []): static
    {
        return $this->node(self::MULTI_SELECT, $config + [
            'searchable'        => true,
            'searchPlaceholder' => Craft::t('influx', 'Search options…'),
        ]);
    }

    /**
     * The reused "Match by" control: a select on the mapping's `match` option.
     * Pass `options` (and optionally override `handle` / `label` / `default`).
     */
    public function matchBy(array $config = []): static
    {
        return $this->select($config + [
            'handle'  => 'match',
            'label'   => Craft::t('influx', 'Match by'),
            'default' => 'id',
        ]);
    }

    /**
     * The reused date-format select on the mapping's `format` option. Pass
     * `options` ({@see \GlueAgency\Influx\fields\Date::formatOptions()}); the
     * label and "auto-detect" default are supplied here.
     */
    public function dateFormat(array $config = []): static
    {
        return $this->select($config + [
            'handle'  => 'format',
            'label'   => Craft::t('influx', 'Date format'),
            'default' => '',
        ]);
    }

    /**
     * The reused "create the related element when no match is found" toggle,
     * on the mapping's `create` option.
     */
    public function createWhenMissing(array $config = []): static
    {
        return $this->lightswitch($config + [
            'handle' => 'create',
            'label'  => Craft::t('influx', 'Create when not found'),
        ]);
    }

    /**
     * Source-node + default rows for the sub-fields a field owns itself — the
     * card that writes the mapping's flat `fields` channel ({@see \GlueAgency\Influx\models\FieldMapping::subMappings()}).
     * `$config` supplies `label` + `subFields` (a list of primitive nodes, e.g.
     * `SchemaBuilder::make()->text([...])->toArray()`), optionally
     * `instructions`. Used by {@see \GlueAgency\Influx\fields\Table}'s columns.
     *
     * Unlike its two fixed-handle siblings ({@see elementSubFields()},
     * {@see matrixFields()}) the handle is a shorthand DEFAULT the caller may
     * override, per the folding convention this class documents: SchemaForm
     * routes the card by node TYPE, so the handle is documentation of which
     * channel the rows land in rather than the routing key.
     */
    public function subFields(array $config = []): static
    {
        return $this->push(['type' => self::SUB_FIELDS] + $config + ['handle' => 'fields']);
    }

    /**
     * Source-node + default rows for the sub-fields of the element a mapping
     * relates — its native attributes (asset alt/title, entry title/slug) and
     * the custom fields of the layouts its sources allow. `$config` supplies
     * `label` + `subFields` (a list of primitive nodes, e.g.
     * `SchemaBuilder::make()->text([...])->toArray()`).
     *
     * ONE card over both sub-field channels, since the element's attributes and
     * its layout's fields are written differently. Each sub-field row may carry
     * an optional `channel` key saying which it lands in: `fields` routes the
     * row through the element's field layout ({@see \GlueAgency\Influx\models\FieldMapping::subMappings()}),
     * while an ABSENT key means `nativeFields` — the channel this node's rows
     * were stored in before the key existed, and the handle forced below.
     *
     * Note the asymmetry with {@see matrixFields()}, whose absent key means
     * `fields`: each node type defaults to the channel ITS rows already used,
     * so a row whose key is forgotten keeps behaving as it did. A native row
     * misrouted to `fields` would be dropped silently at apply time
     * ({@see \GlueAgency\Influx\sync\item\MappingApplier::applySubMappings()}),
     * which is the failure this default avoids.
     *
     * A field must not pair a channel-carrying card with a separate
     * {@see subFields()} card: both would claim the same `fields` channel.
     */
    public function elementSubFields(array $config = []): static
    {
        return $this->push(['type' => self::ELEMENT_SUB_FIELDS, 'handle' => 'nativeFields'] + $config);
    }

    /**
     * One Matrix block type's card: source-node + default rows for its
     * mappable sub-fields, writing the block type's slice of the mapping's
     * `blocks` channel. `$config` supplies `label`, `subFields` and
     * `blockType`.
     *
     * Each sub-field row may carry an optional `channel` key saying which half
     * of the block type's slice it writes: `nativeFields` routes the row to
     * `blocks.<blockType>.nativeFields` (the block's native Title), while an
     * ABSENT key means `blocks.<blockType>.fields` — the custom-field channel,
     * and the stored shape that predates the key.
     */
    public function matrixFields(array $config = []): static
    {
        return $this->push(['type' => self::MATRIX_FIELDS, 'handle' => 'blocks'] + $config);
    }

    /**
     * One sub-field row for a Craft field, configured the way that field is at the
     * top level ({@see \GlueAgency\Influx\services\FieldsService::childRowFor()}).
     *
     * Its default cell IS a node in this vocabulary already — a relation's is
     * {@see ELEMENT} plus an `elementType`, an option field's is {@see SELECT} plus
     * its own options — so the row is that node with the row's identity laid over
     * it. Its extras ride along under `extra`, for the SPA to render behind the
     * row's own disclosure: a nested Assets row gets the `mode` that decides whether
     * a URL is matched or uploaded, a nested Date its format, a nested relation its
     * match-by and its sub-fields. All of it is honoured at sync time, because a
     * sub-row is a whole {@see \GlueAgency\Influx\models\FieldMapping} the applier
     * descends into.
     *
     * A child that declares NEITHER — a nested Matrix or Super Table, whose whole
     * value is block cards a card can't nest — is skipped rather than offered as a
     * row nothing could be mapped through. A nested Table or Link still appears:
     * they have no default cell either, but their columns/parts card is renderable.
     *
     * Without this, every sub-field row was a text box — including a relation's,
     * which is a reference the operator can only pick, not retype.
     *
     * @param array{default: array|null, extra: list<array>} $childRow
     * @param array<string, mixed> $config The row's `handle` / `label` (+ `channel`).
     */
    public function fieldRow(array $childRow, array $config = []): static
    {
        $cell = $childRow['default'] ?? null;
        $extra = $childRow['extra'] ?? [];

        if ($cell === null && $extra === []) {
            return $this;
        }

        if ($extra !== []) {
            $config += ['extra' => $extra];
        }

        return $this->push($config + ($cell ?? []) + ['type' => self::TEXT]);
    }

    /**
     * A native's `value => label` option map as the ordered option list a node
     * carries. No blank lead: that row is {@see defaultSelect()}'s sentinel, not
     * one of the values.
     *
     * The two shapes exist because a node carries an ordered LIST while a
     * descriptor is keyed; {@see \GlueAgency\Influx\fields\Field::optionsAsMap()} is the way back.
     *
     * @param array<string, string> $map
     * @return list<array{value: string, label: string}>
     */
    protected static function optionRows(array $map): array
    {
        $options = [];

        foreach ($map as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    /**
     * Native mappable-field descriptors for an element target's
     * {@see \GlueAgency\Influx\targets\ElementTargetInterface::getMappableFields()},
     * declared with the same fluent field methods as any other schema and
     * grouped under $label. Each field the callback pushes becomes a
     * {@see MappableField} whose row is described the same way a custom field's
     * is — as the three regions of a {@see MappingSchema}.
     *
     * A native declares ONE node, and that node IS its default cell: the method
     * it's pushed with ({@see text()} / {@see select()} / {@see element()}) is the
     * control that cell renders. The source cell is the standard node select, and
     * `extras` — a callback, as everywhere else — is the extras region. So the
     * terse form below says "source select, this default control, these extras"
     * without repeating the region names for the common case.
     *
     *   ->group('Native', fn (MappingSchemaBuilder $g) => $g
     *       ->text(['handle' => 'title', 'name' => 'Title'])
     *       ->select(['handle' => 'enabled', 'name' => 'Enabled', 'options' => ['true' => 'Enabled', ...]])
     *       ->element(['handle' => 'author', 'name' => 'Author', 'elementType' => User::class,
     *           'extras' => fn (MappingSchemaBuilder $b) => $b->matchBy([...])]))
     *
     * A native that renders neither cell — its value comes from the extras, like
     * a user's group toggles — says so with `cells`, whose keys are the region
     * names {@see MappingSchema} documents:
     *
     *   ->text(['handle' => 'groups', 'name' => 'Groups',
     *       'cells' => ['source' => false, 'default' => false], 'extras' => ...])
     *
     * A native the element type hides is left out by not declaring it — see
     * {@see when()}, and {@see \GlueAgency\Influx\targets\EntryTarget::nativeFieldDefinitions()}
     * for the visibility rules that use it.
     *
     * @param callable(self): mixed $fields Pushes the group's fields onto the given builder.
     */
    public function group(string $label, callable $fields): static
    {
        $group = static::make();
        $fields($group);

        foreach ($group->toArray() as $field) {
            $this->push(MappableField::native(
                handle: $field['handle'],
                name: $field['name'],
                group: $label,
                mapping: static::nativeRegions($field)->toArray(),
            ));
        }

        return $this;
    }

    /**
     * One native's declaration as the three regions of its row.
     *
     * The default region re-pushes the declared node itself, minus the keys that
     * describe the DESCRIPTOR rather than the control (`name`, `cells`, `extras`)
     * — and with its `value => label` option map converted to the option list a
     * node carries, which is the one place the two option shapes meet on the
     * native side.
     *
     * A select goes through {@see defaultSelect()} / {@see defaultMultiSelect()},
     * the same presets a field strategy declares its default cell with, so the
     * cell's ergonomics are stated once and a native can't drift from a custom
     * field.
     *
     * `handle` stays: it's the element attribute the row writes, and the SPA's
     * element and icon pickers post it to their render endpoints.
     *
     * @param array<string, mixed> $field
     */
    protected static function nativeRegions(array $field): MappingSchema
    {
        $cells = $field['cells'] ?? [];
        $control = array_diff_key($field, array_flip(['name', 'cells', 'extras']));

        if (isset($control['options'])) {
            $control['options'] = static::optionRows($control['options']);
        }

        return MappingSchema::make([
            'source'  => $cells['source'] ?? true,
            'default' => $cells['default'] ?? function(self $builder) use ($control): void {
                match ($control['type'] ?? self::TEXT) {
                    self::SELECT       => $builder->defaultSelect($control),
                    self::MULTI_SELECT => $builder->defaultMultiSelect($control),
                    default            => $builder->push($control),
                };
            },
            'extra' => $field['extras'] ?? false,
        ]);
    }
}
