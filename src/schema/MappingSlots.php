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
 * And then DOWN: a channel that survives still holds rows, each addressed by a
 * sub-field handle its card may no longer list, and each a whole mapping in its
 * own right ({@see childRegions()}). So the prune recurses — a stale row inside a
 * Matrix block type is worse off than a stale top-level one, since the card
 * renders rows from its roster and never draws the leftover at all: invisible,
 * unclearable, and still resolved at sync time.
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
     * The channels ONE block-type entry inside `blocks` holds. Distinct from
     * `CONTAINER_CHANNELS[MATRIX_FIELDS]`, which is the channel the NODE binds
     * (`blocks` itself) — this is what one type's slice of it holds.
     */
    public const BLOCK_CHANNELS = ['fields', 'nativeFields'];

    /**
     * The channel a roster row without a `channel` key lands in, per container
     * node type — the stored shape that predates the key, and the
     * `defaultChannel` the SPA passes at its two `splitChannels()` call sites
     * (`MatrixFields.vue` => `fields`, `ElementSubFields.vue` => `nativeFields`).
     * Change one, change both.
     */
    public const CHANNEL_DEFAULT = [
        MappingSchemaBuilder::SUB_FIELDS         => 'fields',
        MappingSchemaBuilder::MATRIX_FIELDS      => 'fields',
        MappingSchemaBuilder::ELEMENT_SUB_FIELDS => 'nativeFields',
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
     * Three passes, in order: the top-level slots, then `options`' keys, then the
     * ROWS inside whichever channels survived ({@see pruneChannels()}).
     *
     * @param array $mapping One entry of a link's `mappings`.
     * @param array<string, list<array>> $regions That row's declared regions ({@see MappableField::getMapping()}).
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

        $mapping = static::pruneOptions($mapping, $regions);

        return static::pruneChannels($mapping, $regions);
    }

    /**
     * Second pass: `options`, key by key, dropped once it empties.
     *
     * @param array<string, list<array>> $regions
     */
    protected static function pruneOptions(array $mapping, array $regions): array
    {
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
     * Third pass: the ROWS inside the channels a surviving container node binds.
     *
     * The slot pass answers "may this mapping hold a `blocks` key at all"; this
     * answers "and which rows may be in it". Without it a sub-field dropped from a
     * Matrix block type keeps its stored row forever — the card renders rows from
     * the roster, so the leftover has no cell to edit it, no cell to clear it, and
     * nothing to show for itself but a sync-time resolve.
     *
     * A container node with NO `subFields` key judges nothing: "didn't report a
     * roster" is not "reported an empty roster", the same restraint the region
     * guard in {@see prune()} applies. A present-but-empty roster DOES prune — the
     * card renders, offers no rows, so a stored row has no control.
     *
     * @param array<string, list<array>> $regions
     */
    protected static function pruneChannels(array $mapping, array $regions): array
    {
        $blocks = [];

        foreach ($regions as $nodes) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $type = $node['type'] ?? '';

                // Collected rather than pruned here: the unknown-TYPE drop needs
                // every card at once, since one card can't tell a stale type from
                // a sibling's.
                if ($type === MappingSchemaBuilder::MATRIX_FIELDS) {
                    $blocks[] = $node;

                    continue;
                }

                $channels = static::CONTAINER_CHANNELS[$type] ?? null;

                if ($channels === null || ! isset($node['subFields'])) {
                    continue;
                }

                $mapping = static::pruneRows($mapping, $channels, $node);
            }
        }

        return static::pruneBlocks($mapping, $blocks);
    }

    /**
     * One container's channels: drop the rows its roster no longer lists, prune
     * each survivor against its own sub-field node, collapse a channel that
     * empties.
     *
     * The handle test is a UNION across the node's channels rather than a
     * same-channel match, because that's what the card renders: `flattenChannels()`
     * shows both channels as one handle-keyed table, and `channelFor()` round-trips
     * a row to the channel it was SAVED in. A `fields` row whose roster row now
     * says `nativeFields` is therefore still visible and editable, and the next
     * client write migrates it. A prune removes; it doesn't rewrite.
     *
     * @param list<string> $channels
     */
    protected static function pruneRows(array $mapping, array $channels, array $node): array
    {
        $roster = static::roster($node);
        $rows = [];

        foreach ($channels as $channel) {
            if (! isset($mapping[$channel]) || ! is_array($mapping[$channel])) {
                continue;
            }

            $rows[$channel] = static::pruneRoster($mapping[$channel], $roster);
        }

        foreach (static::dropCollisions($rows, $roster, $node) as $channel => $kept) {
            if ($kept === []) {
                unset($mapping[$channel]);

                continue;
            }

            $mapping[$channel] = $kept;
        }

        return $mapping;
    }

    /**
     * A container's roster as `handle => node`. A row without a handle can't be
     * addressed by a stored mapping, so it contributes nothing.
     *
     * @return array<string, array>
     */
    protected static function roster(array $node): array
    {
        $roster = [];

        foreach ($node['subFields'] ?? [] as $sub) {
            if (! is_array($sub) || ! isset($sub['handle']) || $sub['handle'] === '') {
                continue;
            }

            $roster[(string) $sub['handle']] = $sub;
        }

        return $roster;
    }

    /**
     * One channel's rows, minus the handles the roster dropped, each survivor
     * recursed through {@see prune()} against its own synthesized regions. A row
     * that empties drops out — the empty-shape contract the SPA's writers keep.
     *
     * @param array<string, array> $roster
     */
    protected static function pruneRoster(array $rows, array $roster): array
    {
        $kept = [];

        foreach ($rows as $handle => $row) {
            if (! isset($roster[$handle])) {
                continue;
            }

            if (! is_array($row)) {
                $kept[$handle] = $row;

                continue;
            }

            $pruned = static::prune($row, static::childRegions($roster[$handle]));

            if ($pruned === []) {
                continue;
            }

            $kept[$handle] = $pruned;
        }

        return $kept;
    }

    /**
     * A handle stored in BOTH of a container's channels means one copy is stale:
     * the card renders one row per handle (`flattenChannels()`, `nativeFields`
     * winning), so the other is unreachable. The roster says which channel still
     * applies; the copy in the other one goes. A node type this knows no default
     * for judges nothing.
     *
     * @param array<string, array<string, array>> $rows Kept rows, per channel.
     * @param array<string, array> $roster
     * @return array<string, array<string, array>>
     */
    protected static function dropCollisions(array $rows, array $roster, array $node): array
    {
        if (count($rows) < 2) {
            return $rows;
        }

        $default = static::CHANNEL_DEFAULT[$node['type'] ?? ''] ?? null;

        foreach ($roster as $handle => $sub) {
            $holders = array_keys(array_filter($rows, static fn(array $kept): bool => isset($kept[$handle])));

            if (count($holders) < 2) {
                continue;
            }

            $keep = $sub['channel'] ?? $default;

            if ($keep === null) {
                continue;
            }

            foreach ($holders as $channel) {
                if ($channel === $keep) {
                    continue;
                }

                unset($rows[$channel][$handle]);
            }
        }

        return $rows;
    }

    /**
     * The `blocks` channel, keyed by BLOCK TYPE before it is keyed by handle.
     *
     * A card that didn't name its `blockType` leaves the type-level drop alone (an
     * incomplete roster judges nothing), though the types the other cards DID name
     * still get their rows pruned. Unknown keys on a type entry survive: a key the
     * stored vocabulary can't name is not one this can judge.
     *
     * @param list<array> $nodes Every MATRIX_FIELDS node across the regions.
     */
    protected static function pruneBlocks(array $mapping, array $nodes): array
    {
        if ($nodes === [] || ! isset($mapping['blocks']) || ! is_array($mapping['blocks'])) {
            return $mapping;
        }

        $byType = [];
        $complete = true;

        foreach ($nodes as $node) {
            $type = (string) ($node['blockType'] ?? '');

            if ($type === '') {
                $complete = false;

                continue;
            }

            $byType[$type] = $node;
        }

        $blocks = [];

        foreach ($mapping['blocks'] as $type => $entry) {
            if (! isset($byType[$type])) {
                if (! $complete) {
                    $blocks[$type] = $entry;
                }

                continue;
            }

            if (! is_array($entry)) {
                $blocks[$type] = $entry;

                continue;
            }

            $entry = static::pruneRows($entry, static::BLOCK_CHANNELS, $byType[$type]);

            if ($entry === []) {
                continue;
            }

            $blocks[$type] = $entry;
        }

        if ($blocks === []) {
            unset($mapping['blocks']);

            return $mapping;
        }

        $mapping['blocks'] = $blocks;

        return $mapping;
    }

    /**
     * A sub-field ROW's regions, synthesized from the flat node the roster carries
     * ({@see MappingSchemaBuilder::fieldRow()}) — the one rule here the SPA states
     * in markup instead of in a table.
     *
     * `SubFieldRows.vue` renders a source select unless `cells.source === false`,
     * and that select always carries the `__default__` sentinel, so a child's
     * `useDefault` lives or dies with it exactly as a top-level row's does. The
     * default cell renders unless `cells.default === false`, typed by the node
     * itself, so a note-typed default binds nothing. Extras ride through verbatim.
     *
     * DEPTH BOUND, deliberate: {@see \GlueAgency\Influx\services\FieldsService::childRowFor()}
     * elides extras one card deep and `fieldRow()` omits an empty `extra` key — so
     * at depth 3 "declares no extras" and "extras were cut" are the same shape on
     * the wire, and such a row loses its `options` and nested channels. Taken
     * knowingly: the builder can't render or clear those either, and stored config
     * shouldn't claim what no UI can show. Cells stay known at every depth, so
     * `node` / `default` / `useDefault` are never touched by the ambiguity.
     *
     * @return array<string, list<array>>
     */
    protected static function childRegions(array $node): array
    {
        $cells = $node['cells'] ?? [];
        $regions = [];

        if (($cells['source'] ?? true) !== false) {
            $regions['source'] = [[
                'type'     => SchemaBuilder::SELECT,
                'sentinel' => [MappingSchemaBuilder::USE_DEFAULT => 'useDefault'],
            ]];
        }

        if (($cells['default'] ?? true) !== false) {
            $regions['default'] = [['type' => $node['type'] ?? SchemaBuilder::TEXT]];
        }

        if (isset($node['extra']) && is_array($node['extra'])) {
            $regions['extra'] = $node['extra'];
        }

        return $regions;
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
