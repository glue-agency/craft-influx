import { setSlot } from './slots.js';

/**
 * Pure helpers for the link's `mappings` payload — extracted from
 * MappingRow so the pruning rules that keep Project Config clean exist
 * once and are unit-testable without mounting components.
 */

/**
 * Drop empty values from a flat object: '', null, undefined, false, and
 * empty objects/arrays. This is the shape contract for everything written
 * into `mappings[handle].options` — Project Config YAML shouldn't fill up
 * with noise keys.
 *
 * @param {Object<string, *>} obj
 * @returns {Object<string, *>}
 */
export function pruneEmpty(obj) {
    const out = {};
    for (const key of Object.keys(obj || {})) {
        const value = obj[key];
        if (value === '' || value === null || value === undefined || value === false) continue;
        if (typeof value === 'object' && !Object.keys(value).length) continue;
        out[key] = value;
    }
    return out;
}

/**
 * Put one field's whole mapping back into the map, dropping the handle when
 * nothing is left on it — so Project Config never carries an empty row. Pure:
 * returns a new mappings object, never mutates.
 *
 * The map level is all this owns. Which SLOT of that mapping a control writes, and
 * what counts as empty for it, is `lib/slots.js`'s business — and it works on the
 * mapping alone, which is what lets a nested sub-field row reuse it.
 *
 * @param {Object<string, import('../types.js').Mapping>} mappings
 * @param {string} handle
 * @param {?import('../types.js').Mapping} mapping
 * @returns {Object<string, import('../types.js').Mapping>}
 */
export function replaceMapping(mappings, handle, mapping) {
    const next = { ...mappings };

    if (! mapping || Object.keys(mapping).length === 0) {
        delete next[handle];
    } else {
        next[handle] = mapping;
    }

    return next;
}

/**
 * Write one slot of one field's mapping — the map-level convenience over
 * {@see replaceMapping} + `slots.setSlot`, for callers that hold the map and know
 * the key (the Feed Me import, the auto-match writer).
 *
 * @param {Object<string, import('../types.js').Mapping>} mappings
 * @param {string} handle
 * @param {string} key
 * @param {*} value
 * @returns {Object<string, import('../types.js').Mapping>}
 */
export function setMappingSlot(mappings, handle, key, value) {
    return replaceMapping(mappings, handle, setSlot(mappings?.[handle], key, value));
}

/**
 * Whether a field counts as mapped — the rule behind every "N mapped" pill and
 * the sidebar's total, so the two can't drift apart.
 *
 * Normally that means a source node was picked. A row with no source CELL, though,
 * has nowhere to pick one: its value comes entirely from its sub-mappings (a
 * Matrix, a Table), so anything saved on the row counts. Read off the declared
 * regions rather than a flag — a row that renders no source cell simply doesn't
 * declare one.
 *
 * A field nothing can be mapped to is not a special case: it DOES declare a source
 * region (holding a note), so it falls to the node rule and is never mapped, which
 * is exactly right.
 *
 * @param {import('../types.js').MappableField} field
 * @param {?import('../types.js').Mapping} mapping
 * @returns {boolean}
 */
export function isMapped(field, mapping) {
    if (! field.mapping?.source?.length) {
        return Object.keys(mapping || {}).length > 0;
    }

    return !! mapping?.node;
}

/**
 * Drop a whole list of field mappings — every slot at once, sub-field
 * channels included. The group-level "Clear" affordance, which has to land
 * as a single store assignment so the rows it wipes re-render once. Pure:
 * returns a new mappings object, never mutates, and an unknown handle is a
 * no-op.
 *
 * @param {Object<string, import('../types.js').Mapping>} mappings
 * @param {string[]} handles
 * @returns {Object<string, import('../types.js').Mapping>}
 */
export function clearMappings(mappings, handles) {
    const next = { ...mappings };
    for (const handle of handles || []) {
        delete next[handle];
    }

    return next;
}

/**
 * Fill in the mappings a fetched sample can fill in on its own: the report's
 * `mappingSuggestions` name a remote key per destination handle, and this
 * applies each one whose node is really in the sample and whose field is
 * really in the mapping tree.
 *
 * Never overwrites: a handle that already carries a node — or "use default" —
 * is left exactly as it is, so a run of this can only ever add. The matched
 * handles come back so the caller can flag those rows as machine-filled; to
 * the saved config they are ordinary mappings.
 *
 * @param {Object<string, import('../types.js').Mapping>} mappings
 * @param {Array<{field: string, node: string}>} suggestions Report suggestions.
 * @param {string[]} handles The handles the mapping tree offers.
 * @param {Array<import('../types.js').SelectOption|string>} discovered Sample nodes.
 * @returns {{mappings: Object<string, import('../types.js').Mapping>, matched: string[]}}
 */
export function autoMatchMappings(mappings, suggestions, handles, discovered) {
    const offered = new Set(handles || []);
    const available = new Set((discovered || [])
        .map((entry) => (typeof entry === 'string' ? entry : entry?.value))
        .filter(Boolean));

    const next = { ...mappings };
    const matched = [];

    for (const suggestion of suggestions || []) {
        const handle = suggestion?.field;
        const node = suggestion?.node;

        if (! handle || ! node || ! offered.has(handle) || ! available.has(node)) continue;

        const current = next[handle] || {};

        if (current.node || current.useDefault) continue;

        next[handle] = { ...current, node };
        matched.push(handle);
    }

    return { mappings: next, matched };
}

/**
 * Render a node path as a select option. The label keeps the raw dot
 * syntax ('meta.id') so typing a dot-path into the dropdown search matches.
 *
 * @param {string} value
 * @returns {import('../types.js').SelectOption}
 */
export function nodeOption(value) {
    return { value, label: String(value) };
}

/**
 * The item-level nodes a report discovered, or null for "can't know" — which
 * is what every missing-mapping check treats as "flag nothing".
 *
 * A partial report (no list of items resolved from the response) carries an
 * EMPTY flatNodes list, not a missing one, and an empty list would otherwise
 * read as "the sample has none of your saved nodes" and light up every mapped
 * row. No sample and a sample with no item are the same state here.
 *
 * @param {?import('../types.js').SampleReport} sample
 * @returns {?import('../types.js').SelectOption[]}
 */
export function discoveredNodes(sample) {
    return sample?.flatNodes?.length ? sample.flatNodes : null;
}

/**
 * Merge sample-discovered node options with saved-but-not-discovered node
 * paths, deduplicated, discovered first. Saved nodes stay listed so a row
 * whose node fell out of the latest sample still renders a legible selected
 * option (the row-level "missing" badge carries the warning).
 *
 * @param {Array<import('../types.js').SelectOption|string>} discovered
 * @param {string[]} savedNodes
 * @returns {import('../types.js').SelectOption[]}
 */
export function mergeNodeOptions(discovered, savedNodes = []) {
    const out = [];
    const seen = new Set();

    for (const entry of discovered || []) {
        const option = typeof entry === 'string' ? nodeOption(entry) : entry;
        if (!option || option.value == null || option.value === '' || seen.has(option.value)) continue;
        out.push(option);
        seen.add(option.value);
    }

    for (const value of savedNodes || []) {
        if (!value || seen.has(value)) continue;
        out.push(nodeOption(value));
        seen.add(value);
    }

    return out;
}
