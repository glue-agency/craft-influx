<?php

namespace GlueAgency\Influx\schema;

/**
 * Which stored slots of ONE mapping a row's declared regions can write — the PHP
 * side of the rule `src/web/assets/cp/src/builder/lib/slots.js` owns for the SPA.
 *
 * The SPA needs it to route an edit to the right slot; the server needs it to
 * answer the opposite question on save: which stored slots does this row still
 * have a control for? A field's mapping surface changes under stored config —
 * a custom-field integration is hooked and a single-node field grows sub-fields,
 * a Preparse strategy declares its field uncomputable, an element type stops
 * offering a native — and the slots left behind have no cell to edit them, no
 * cell to clear them, and nothing but a "missing mapping" badge to show for
 * themselves. {@see \GlueAgency\Influx\services\LinksService::pruneMappings()}
 * strips them; this says what "them" is.
 *
 *   region 'source'         → the mapping's `node`, plus the flags its sentinels raise
 *   region 'default'        → the mapping's `default`
 *   region 'extra', leaf    → one key of `options`
 *   region 'extra', card    → whole channels (`fields` / `nativeFields` / `blocks`)
 *
 * TWO IMPLEMENTATIONS OF ONE RULE, deliberately: the regions are already on the
 * wire, so the SPA can't call this and this can't call the SPA. They're pinned
 * against each other by {@see \GlueAgency\Influx\Tests\unit\schema\MappingSlotsTest}
 * and `builder/lib/__tests__/slots.test.js` — change one, change both.
 *
 * A {@see SchemaBuilder::NOTE} is skipped everywhere: it's the one node type that
 * renders without binding anything, which is exactly how a strategy says "this
 * field has a source region and still can't be mapped"
 * ({@see \GlueAgency\Influx\integrations\jalendport\preparse\PreparseField::schema()}).
 * Counting it would keep the stale `node` this exists to remove.
 *
 * All static — regions in, slot names out, no state.
 */
class MappingSlots
{
    /**
     * The stored slot a region's leaf nodes bind. A region absent from here binds
     * no single slot: `extra` writes into `options`, handled separately, since its
     * leaves each own one KEY of that slot rather than the slot itself.
     */
    public const REGION_SLOT = [
        'source'  => 'node',
        'default' => 'default',
    ];

    /**
     * The stored channels a container node binds, by node type. A type absent from
     * here is a leaf: it binds one value, not a channel.
     */
    public const CONTAINER_CHANNELS = [
        MappingSchemaBuilder::SUB_FIELDS         => ['fields'],
        MappingSchemaBuilder::MATRIX_FIELDS      => ['blocks'],
        MappingSchemaBuilder::ELEMENT_SUB_FIELDS => ['fields', 'nativeFields'],
    ];

    /**
     * Every slot the stored mapping vocabulary knows — {@see \GlueAgency\Influx\models\FieldMapping::fromConfig()}
     * reads exactly these.
     *
     * The prune's ALLOW-list is what a row's regions bind; this is the list it's
     * allowed to remove from. A stored key in neither survives: a key the
     * vocabulary can't name is not one this can judge, the same restraint
     * {@see \GlueAgency\Influx\models\Link::pruneProcessingForTarget()} shows an
     * unknown processing value.
     */
    public const KNOWN_SLOTS = ['node', 'useDefault', 'default', 'options', 'fields', 'nativeFields', 'blocks'];

    /**
     * Strip the slots `$regions` gives no control for, returning the cleaned
     * mapping. Idempotent.
     *
     * A no-op on regions it can't read — nothing recognizable from
     * {@see MappingSchema::REGIONS}, which covers both an empty set and a shape
     * from some other vocabulary. "Didn't report a row" is not "reported a row
     * that binds nothing": the first must leave the mapping alone, and only the
     * second may empty it. A row that genuinely binds nothing still declares a
     * region to say so, the way a Preparse field declares a source region holding
     * a note. Getting this backwards deletes every mapping on the link, so it's
     * checked against the region names rather than mere emptiness.
     *
     * `options` is pruned key by key and dropped once it empties, so a row that
     * kept one option doesn't keep the five it lost.
     *
     * @param array $mapping One entry of a link's `mappings`.
     * @param array<string, list<array>> $regions That row's declared regions ({@see MappableField::$mapping}).
     */
    public static function prune(array $mapping, array $regions): array
    {
        if (array_intersect_key($regions, array_flip(MappingSchema::REGIONS)) === []) {
            return $mapping;
        }

        $bound = static::slots($regions);

        foreach (static::KNOWN_SLOTS as $slot) {
            if (in_array($slot, $bound, true)) {
                continue;
            }

            unset($mapping[$slot]);
        }

        if (! isset($mapping['options']) || ! is_array($mapping['options'])) {
            return $mapping;
        }

        $options = array_intersect_key($mapping['options'], array_flip(static::optionKeys($regions)));

        if ($options === []) {
            unset($mapping['options']);

            return $mapping;
        }

        $mapping['options'] = $options;

        return $mapping;
    }

    /**
     * The top-level slots `$regions` can write, deduplicated.
     *
     * A sentinel contributes the FLAG it raises alongside the slot it stands in
     * for — one control over two slots, so `useDefault` lives or dies with the
     * source node that offers it rather than being named here.
     *
     * @param array<string, list<array>> $regions
     * @return list<string>
     */
    public static function slots(array $regions): array
    {
        $slots = [];

        foreach ($regions as $region => $nodes) {
            foreach ($nodes as $node) {
                if (! is_array($node) || ($node['type'] ?? '') === SchemaBuilder::NOTE) {
                    continue;
                }

                $channels = static::CONTAINER_CHANNELS[$node['type'] ?? ''] ?? null;

                if ($channels !== null) {
                    $slots = array_merge($slots, $channels);

                    continue;
                }

                if ($region === 'extra') {
                    $slots[] = 'options';

                    continue;
                }

                $slot = static::REGION_SLOT[$region] ?? null;

                if ($slot === null) {
                    continue;
                }

                $slots[] = $slot;
                $slots = array_merge($slots, array_values($node['sentinel'] ?? []));
            }
        }

        return array_values(array_unique($slots));
    }

    /**
     * The `options` keys `$regions` can write — the handle of every extras leaf.
     * A container binds a channel rather than an option and a note binds nothing,
     * so neither contributes.
     *
     * @param array<string, list<array>> $regions
     * @return list<string>
     */
    public static function optionKeys(array $regions): array
    {
        $keys = [];

        foreach ($regions['extra'] ?? [] as $node) {
            if (! is_array($node) || ($node['type'] ?? '') === SchemaBuilder::NOTE) {
                continue;
            }

            if (isset(static::CONTAINER_CHANNELS[$node['type'] ?? ''])) {
                continue;
            }

            if (! isset($node['handle']) || $node['handle'] === '') {
                continue;
            }

            $keys[] = (string) $node['handle'];
        }

        return array_values(array_unique($keys));
    }
}
