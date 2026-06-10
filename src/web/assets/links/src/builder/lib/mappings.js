/**
 * Pure helpers for the link's `mappings` payload — extracted from
 * MappingRow / MappingExtras so the pruning rules that keep Project Config
 * clean exist once and are unit-testable without mounting components.
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
 * Write one slot (`node`, `default`, `options`, `nativeFields`, ...) of one
 * mapping, dropping empty values — and the whole handle when its last slot
 * empties out. Pure: returns a new mappings object, never mutates.
 *
 * @param {Object<string, import('../types.js').Mapping>} mappings
 * @param {string} handle
 * @param {string} key
 * @param {*} value
 * @returns {Object<string, import('../types.js').Mapping>}
 */
export function setMappingSlot(mappings, handle, key, value) {
    const current = { ...(mappings?.[handle] || {}) };

    const isEmpty = value === '' || value === null || value === undefined
        || (typeof value === 'object' && value !== null && Object.keys(value).length === 0);

    if (isEmpty) {
        delete current[key];
    } else {
        current[key] = value;
    }

    const next = { ...mappings };
    if (Object.keys(current).length === 0) {
        delete next[handle];
    } else {
        next[handle] = current;
    }
    return next;
}

/**
 * Render a node path as a select option ('meta.id' → 'meta → id').
 *
 * @param {string} value
 * @returns {import('../types.js').SelectOption}
 */
export function nodeOption(value) {
    return { value, label: String(value).replace(/\./g, ' → ') };
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
