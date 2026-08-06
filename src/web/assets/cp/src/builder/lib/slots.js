/**
 * THE only place that knows which slot of ONE stored mapping a schema node reads
 * and writes.
 *
 * A mapping row's UI is declared by PHP as regions of schema nodes
 * (schema/MappingSchema.php), and a generic renderer dispatches them by `type`
 * alone. Which leaves one thing a `type` can't say: where the value goes. That
 * is what this module answers, and it answers it from the REGION — so no node
 * has to declare a slot, and adding a control kind stays a component plus a
 * registry entry.
 *
 *   region 'source'         → the mapping's `node`
 *   region 'default'        → the mapping's `default`
 *   region 'extra', leaf    → one key of `options`
 *   region 'extra', card    → a whole channel (`fields` / `nativeFields` / `blocks`)
 *
 * Everything works on ONE mapping object rather than the `mappings` map, which is
 * what lets a NESTED row reuse it: a sub-field row's stored shape is itself a whole
 * mapping ({@see ./channels.js}), so a nested Assets row's `mode` is one key of its
 * own `options`, addressed exactly as a top-level row's is. Dropping an emptied
 * mapping from the map it lives in belongs to whoever owns that map — the row for
 * `link.mappings`, the card for its rows.
 *
 * Two things stay explicit, because neither follows from the region. A source node
 * carries a `sentinel` map: one control over two slots, where picking `__default__`
 * sets the `useDefault` flag instead of a node. And a container node binds whole
 * channels rather than one value — CONTAINER_CHANNELS below names them per node
 * type, since that is a fact about the stored shape and this module owns those.
 *
 * Pure, so it is unit-tested without mounting anything.
 */

/** Which top-level slot each cell region writes. */
const REGION_SLOT = { source: 'node', default: 'default' };

/**
 * The stored channels a container node binds, by node type. An absent entry
 * means the node is a leaf: it binds one value, not a channel.
 */
const CONTAINER_CHANNELS = {
    subFields: ['fields'],
    matrixFields: ['blocks'],
    elementSubFields: ['fields', 'nativeFields'],
};

/** Values that mean "nothing stored", and so prune the slot away. */
const isEmpty = (value) => value === '' || value === null || value === undefined || value === false
    || (typeof value === 'object' && value !== null && Object.keys(value).length === 0);

/**
 * The channels a container node binds, or null for a leaf node.
 *
 * @param {Object} node
 * @returns {?string[]}
 */
export function channelsFor(node) {
    return CONTAINER_CHANNELS[node?.type] || null;
}

/**
 * The slot one LEAF node reads and writes. A container node has no single slot
 * — ask channelsFor() instead.
 *
 * @param {string} region
 * @param {Object} node
 * @returns {string}
 */
export function slotFor(region, node) {
    return region === 'extra' ? 'options' : REGION_SLOT[region];
}

/**
 * Write one slot of one mapping, dropping it when the value is empty so the saved
 * Project Config doesn't fill up with noise. Pure: returns a new mapping.
 *
 * @param {?import('../types.js').Mapping} mapping
 * @param {string} key
 * @param {*} value
 * @returns {import('../types.js').Mapping}
 */
export function setSlot(mapping, key, value) {
    const next = { ...(mapping || {}) };

    if (isEmpty(value)) {
        delete next[key];
    } else {
        next[key] = value;
    }

    return next;
}

/**
 * The value a leaf node should display: what the mapping holds, else the node's
 * declared `default`, else null.
 *
 * A declared default is display-only — it is never written unless the operator
 * touches the control, so an untouched mapping stays free of noise keys. Null
 * rather than '' as the empty, because that is the empty every control here
 * accepts: the pickers want null, and the text and select controls coerce.
 *
 * A sentinel reads back as the value that produced it, so the control shows the
 * row the operator picked rather than an empty select beside a raised flag.
 *
 * @param {?import('../types.js').Mapping} mapping
 * @param {string} region
 * @param {Object} node
 * @returns {*}
 */
export function readNode(mapping, region, node) {
    if (region === 'extra') {
        return mapping?.options?.[node.handle] ?? node.default ?? null;
    }

    for (const [value, flag] of Object.entries(node.sentinel || {})) {
        if (mapping?.[flag]) return value;
    }

    return mapping?.[slotFor(region, node)] ?? node.default ?? null;
}

/**
 * Write a leaf node's value back, returning a new mapping.
 *
 * @param {?import('../types.js').Mapping} mapping
 * @param {string} region
 * @param {Object} node
 * @param {*} value
 * @returns {import('../types.js').Mapping}
 */
export function writeNode(mapping, region, node, value) {
    if (region === 'extra') {
        return setSlot(mapping, 'options', setSlot(mapping?.options, node.handle, value));
    }

    const slot = slotFor(region, node);
    const sentinels = Object.entries(node.sentinel || {});
    let next = mapping || {};

    if (sentinels.length) {
        const picked = node.sentinel[value];

        // One control, two slots: a sentinel pick clears the slot it stands in
        // for and raises its flag instead.
        if (picked) {
            return setSlot(setSlot(next, slot, ''), picked, true);
        }

        // Any other pick lowers every flag this control owns before writing —
        // including the empty pick, which is a deliberate "no mapping" rather
        // than a reason to leave "use default" standing.
        for (const [, flag] of sentinels) {
            next = { ...next, [flag]: false };
        }
    }

    return setSlot(next, slot, value);
}

/**
 * The channels one container node binds, as the object it takes: only the ones its
 * type names, and `{}` for a channel nothing is saved in yet.
 *
 * A container binds a WHOLE channel rather than one value because the shape it
 * edits is a map of rows, and which row goes in which channel is the schema's
 * business rather than the table's ({@see ./channels.js}). That's the second
 * binding arity, and the only reason a renderer needs to know a node isn't a leaf.
 *
 * @param {?import('../types.js').Mapping} mapping
 * @param {Object} node
 * @returns {Object<string, Object>}
 */
export function readChannels(mapping, node) {
    const channels = {};

    for (const channel of channelsFor(node) || []) {
        channels[channel] = mapping?.[channel] || {};
    }

    return channels;
}

/**
 * Write back whichever channels a container emitted — each a full replacement, so
 * an emptied one prunes off the mapping.
 *
 * @param {?import('../types.js').Mapping} mapping
 * @param {Object<string, Object>} channels
 * @returns {import('../types.js').Mapping}
 */
export function writeChannels(mapping, channels) {
    let next = mapping || {};

    for (const [channel, rows] of Object.entries(channels || {})) {
        next = setSlot(next, channel, rows);
    }

    return next;
}
