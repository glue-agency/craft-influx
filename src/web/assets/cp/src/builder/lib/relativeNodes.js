import { nodeOption } from './mappings.js';

/**
 * Node discovery for a Matrix row's block sub-fields — the one place that reads
 * the fetched sample RELATIVE to one element of the list the row names.
 *
 * Everything else in the builder maps against paths that are absolute to a feed
 * item, which is all `FeedInspector` ever produced. A Matrix block's sub-field
 * paths are not: they address one element of the row's list, and the element a
 * path is relative to differs per block type under two of the three sources. So
 * the absolute list is not merely useless here — offering it is offering paths
 * that would be wrong to pick, which is the state this replaces.
 *
 * Client-side rather than a second PHP report, because the report already ships
 * `sampleItem` whole and the list node can change without a refetch: a row
 * repointed at another list re-derives its nodes from the sample already in the
 * store.
 *
 * PHP TWINS, and the reason each is mirrored rather than approximated:
 *   - {@see leafPaths} ⇄ `FeedInspector::flattenLeafPaths()`. A suggested path
 *     that `RemoteItem::get()` reads differently is worse than no suggestion —
 *     it maps, saves, and silently resolves nothing at sync time.
 *   - {@see typeOfElement} ⇄ `Matrix::assignType()`. What the picker attributes
 *     an element to has to be what the sync attributes it to, or a card offers
 *     nodes out of elements it will never receive.
 *
 * One deliberate divergence from FeedInspector: it walks `list[0]` alone, which
 * is right for a feed item (every item is the same shape) and wrong for a block
 * list (element 0 is ONE block type). Leaves are unioned across every element
 * belonging to the type instead — see {@see relativeNodesFor}.
 */

/** The block sources, as `MatrixBlockSource` backs them. Change one, change both. */
export const LIST_BY_KEY = 'listByKey';
export const LIST_BY_NODE = 'listByNode';
export const LIST_SINGLE = 'listSingle';

/** The option key holding one block type's feed alias — `Matrix::sourceKeyOption()`. */
export const sourceKeyOption = (typeHandle) => `sourceKey_${typeHandle}`;

/** The `typeNode` default the strategy reads when the option is unset. */
const DEFAULT_TYPE_NODE = 'type';

const isPlainObject = (value) => typeof value === 'object' && value !== null && ! Array.isArray(value);

const isFilledObject = (value) => isPlainObject(value) && Object.keys(value).length > 0;

/** `FeedInspector::looksLikeListOfObjects()`. */
const looksLikeListOfObjects = (value) => Array.isArray(value) && value.length > 0 && isPlainObject(value[0]);

/**
 * Resolve a dot path against a decoded sample, for the one read this module
 * needs: the row's list node, and a discriminator node on an element. Plain
 * segment-by-segment descent — no list fan-out, because both reads address a
 * single value and a fanned-out read has no single answer here.
 */
export function valueAt(value, path) {
    if (! path) return value;

    let current = value;

    for (const segment of String(path).split('.')) {
        if (current === null || typeof current !== 'object') return undefined;
        current = current[segment];
    }

    return current;
}

/**
 * Every leaf path of one element, dot-joined — the mirror of
 * `FeedInspector::flattenLeafPaths()`, down to which branches contribute their
 * own path and which contribute only their children's.
 *
 * @param {*} value
 * @param {string[]} prefix
 * @returns {string[]}
 */
export function leafPaths(value, prefix = []) {
    if (value === null || typeof value !== 'object') {
        return [prefix.length ? prefix.join('.') : ''];
    }

    if (Array.isArray(value) ? value.length === 0 : Object.keys(value).length === 0) {
        return [];
    }

    if (Array.isArray(value)) {
        return leafPaths(value[0], prefix);
    }

    const paths = [];

    for (const [key, child] of Object.entries(value)) {
        const childPrefix = [...prefix, key];

        // A nested object contributes its leaves and NOT its own path: there is
        // no value at the object itself to map.
        if (isFilledObject(child)) {
            paths.push(...leafPaths(child, childPrefix));

            continue;
        }

        paths.push(childPrefix.join('.'));

        // A list of objects is a node in its own right (a relation mapping takes
        // the whole list) AND exposes its elements' leaves under the same key,
        // with the index collapsed — the fan-out `RemoteItem::get()` performs.
        if (looksLikeListOfObjects(child)) {
            paths.push(...leafPaths(child[0], childPrefix));
        }
    }

    return paths;
}

/**
 * The list a Matrix row's source node resolves to in the sample, or null when
 * it doesn't resolve to one — no sample, no node picked, a path that misses, or
 * a path landing on something that isn't a list.
 *
 * @param {?Object} sampleItem
 * @param {?string} node
 * @returns {?Array}
 */
export function listAt(sampleItem, node) {
    if (! isPlainObject(sampleItem) || ! node) return null;

    const value = valueAt(sampleItem, node);

    return Array.isArray(value) ? value : null;
}

/**
 * The feed key => block-type handle map a source matches elements against: each
 * type's alias option, falling back to its own handle — `Matrix::typeForSourceKey()`.
 *
 * @param {string[]} handles Every block type the field declares.
 * @param {Object} options The row's stored `options`.
 * @returns {Object<string, string>}
 */
export function typeKeys(handles, options = {}) {
    const keys = {};

    for (const handle of handles || []) {
        const alias = options?.[sourceKeyOption(handle)];
        keys[alias || handle] = handle;
    }

    return keys;
}

/**
 * The block type one element belongs to, and the payload its sub-field paths
 * are relative to — the mirror of `Matrix::assignType()`.
 *
 * Returns null when no configured type claims the element, which is the same
 * "skip it" the sync applies rather than an error.
 *
 * @returns {?{handle: string, payload: *}}
 */
export function typeOfElement(element, source, keys, options = {}, handles = []) {
    if (source === LIST_SINGLE) {
        const handle = handles[0] ?? null;

        return handle === null ? null : { handle, payload: element };
    }

    if (source === LIST_BY_NODE) {
        const value = valueAt(element, options?.typeNode || DEFAULT_TYPE_NODE);

        if (typeof value !== 'string' && typeof value !== 'number') return null;

        const handle = keys[String(value)] ?? null;

        return handle === null ? null : { handle, payload: element };
    }

    if (! isPlainObject(element)) return null;

    // The ELEMENT's keys are walked, not the configured types, so an element
    // carrying metadata beside its payload still resolves — and the first key
    // naming a type wins, exactly as the strategy decides it.
    for (const [key, payload] of Object.entries(element)) {
        if (typeof payload !== 'object' || payload === null) continue;

        const handle = keys[key] ?? null;

        if (handle !== null) return { handle, payload };
    }

    return null;
}

/**
 * The relative source-node options for ONE block type's card, unioned across
 * every element of the list that belongs to that type.
 *
 * The union is the whole point: a block list's element 0 is one type, so the
 * first-element walk that serves a feed item would leave every other card empty
 * — and a card with no options is what sent an operator to typing paths in the
 * first place. Order follows first appearance, so the shape of the first block
 * of a type reads top-down.
 *
 * @param {?Array} list The resolved list ({@see listAt}).
 * @param {string} blockType The card's own block-type handle.
 * @param {string} source The row's `options.blockSource`.
 * @param {Object} options The row's stored `options`.
 * @param {string[]} handles Every block type the field declares.
 * @returns {import('../types.js').SelectOption[]}
 */
export function relativeNodesFor(list, blockType, source, options = {}, handles = []) {
    if (! Array.isArray(list)) return [];

    const keys = typeKeys(handles, options);
    const seen = new Set();
    const out = [];

    for (const element of list) {
        const assigned = typeOfElement(element, source, keys, options, handles);

        if (assigned === null || assigned.handle !== blockType) continue;

        for (const path of leafPaths(assigned.payload)) {
            if (path === '' || seen.has(path)) continue;

            seen.add(path);
            out.push(nodeOption(path));
        }
    }

    return out;
}

/**
 * The keys the list's own elements offer for claiming a block type — what the
 * feed actually calls the things in it, so the key can be picked rather than
 * typed and hoped for.
 *
 * Read the way {@see typeOfElement} reads them, or the list would offer keys the
 * sync won't accept: under LIST_BY_KEY only an element key whose value is a
 * container names a type (a scalar sibling like `id` is metadata), and under
 * LIST_BY_NODE it's the discriminator's own values. LIST_SINGLE claims nothing
 * by name and so offers nothing.
 *
 * Suggestions, never the allowed set — a sample is ONE page, and a block type
 * absent from it is not a block type absent from the feed. The control that
 * renders these keeps accepting a typed value.
 *
 * @param {?Array} list
 * @param {string} source
 * @param {Object} options The row's stored `options`.
 * @returns {import('../types.js').SelectOption[]}
 */
export function feedKeysIn(list, source, options = {}) {
    if (! Array.isArray(list) || source === LIST_SINGLE) return [];

    const seen = new Set();
    const out = [];

    const offer = (key) => {
        const value = String(key);

        if (value === '' || seen.has(value)) return;

        seen.add(value);
        out.push(nodeOption(value));
    };

    for (const element of list) {
        if (source === LIST_BY_NODE) {
            const value = valueAt(element, options?.typeNode || DEFAULT_TYPE_NODE);

            if (typeof value === 'string' || typeof value === 'number') offer(value);

            continue;
        }

        if (! isPlainObject(element)) continue;

        for (const [key, payload] of Object.entries(element)) {
            if (typeof payload === 'object' && payload !== null) offer(key);
        }
    }

    return out;
}

/**
 * The block source the sample's own shape implies, or null when it implies none
 * — no list, or a list nothing recognises.
 *
 * Detection is deliberately conservative and matches on HANDLES rather than on
 * the alias options: it runs to help configure a row that isn't configured yet,
 * and at that point no alias has been typed. A feed that spells its types
 * differently is exactly the case detection can't answer, so it says so by
 * returning null instead of guessing a shape the operator then has to undo.
 *
 * The order matters. LIST_BY_KEY is tested first because its shape is the
 * narrower claim — an element whose own key names a type — and a keyed element
 * carrying a `type` node too would otherwise read as noded.
 *
 * @param {?Array} list
 * @param {string[]} handles Every block type the field declares.
 * @returns {?{source: string, typeNode?: string}}
 */
export function detectBlockSource(list, handles = []) {
    if (! Array.isArray(list) || list.length === 0 || ! handles.length) return null;

    const known = new Set(handles);
    const objects = list.filter(isPlainObject);

    if (objects.length === 0) return null;

    const keyed = objects.every((element) => Object.entries(element)
        .some(([key, payload]) => known.has(key) && typeof payload === 'object' && payload !== null));

    if (keyed) return { source: LIST_BY_KEY };

    // A discriminator has to be present on EVERY element and name a known type
    // on every one of them — a node that only sometimes holds a handle is a
    // content field that happens to overlap, not the type node.
    const candidates = Object.keys(objects[0]).filter((key) => objects.every((element) => {
        const value = element[key];

        return (typeof value === 'string' || typeof value === 'number') && known.has(String(value));
    }));

    if (candidates.length) return { source: LIST_BY_NODE, typeNode: candidates[0] };

    // Nothing names a type, but the list IS a list of objects: one type for all
    // of them is the only reading left.
    if (objects.length === list.length) return { source: LIST_SINGLE };

    return null;
}
