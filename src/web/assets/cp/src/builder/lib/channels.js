/**
 * The channel arithmetic behind a sub-field card that spans BOTH mapping
 * channels — `fields` (resolved through the element's field layout) and
 * `nativeFields` (assigned straight onto the element attribute).
 *
 * A card is one table to the editor: rows are addressed by bare handle, and
 * which channel each row is stored in is a property of the SCHEMA, not of the
 * table. These helpers are the split and the join, kept pure so both consumers
 * — a Matrix block type's card and a related element's card — share one
 * implementation instead of one each.
 *
 * The two channels are not interchangeable: a `fields` row whose handle isn't a
 * layout field is silently skipped at sync time, so a native row landing there
 * would go missing without a word. Hence `defaultChannel` is passed in by the
 * caller rather than assumed here — each node type's default is the channel its
 * rows were stored in before the `channel` key existed.
 */

/**
 * Both channels as ONE row map for the table to render.
 *
 * `nativeFields` wins a collision. PHP dedupes the two row sets before they
 * reach the wire, so a collision means a stale saved handle rather than a
 * schema that offers both — and the native is the one that still applies.
 *
 * @param {{fields?: Object, nativeFields?: Object}} saved
 * @returns {Object<string, import('../types.js').Mapping>}
 */
export function flattenChannels(saved) {
    return { ...(saved?.fields || {}), ...(saved?.nativeFields || {}) };
}

/**
 * The channel one handle's row belongs in: what its schema node declares, else
 * the channel it was SAVED in, else the caller's default.
 *
 * The saved-in fallback is what makes a stale handle — one no longer among the
 * card's `subFields`, e.g. a since-removed custom field or an Alt row on a
 * volume that dropped its Alt field — round-trip back where it came from
 * instead of silently switching channels on the next write.
 *
 * @param {string} handle
 * @param {Array<{handle: string, channel?: string}>} subFields The card's schema rows.
 * @param {{fields?: Object, nativeFields?: Object}} saved
 * @param {string} defaultChannel
 * @returns {string}
 */
export function channelFor(handle, subFields, saved, defaultChannel) {
    const sub = (subFields || []).find(s => s.handle === handle);

    if (sub) return sub.channel || defaultChannel;

    if (saved?.nativeFields?.[handle]) return 'nativeFields';
    if (saved?.fields?.[handle]) return 'fields';

    return defaultChannel;
}

/**
 * Partition a rewritten row map back into the two channels.
 *
 * Both keys always come back, empty where a channel has no rows — collapsing
 * an emptied channel is the caller's business, because what "collapse" means
 * differs per consumer (a Matrix type entry drops the key and may drop the
 * whole type; a card writing two separate mapping slots just writes `{}`).
 *
 * @param {Object<string, import('../types.js').Mapping>} rows
 * @param {Array<{handle: string, channel?: string}>} subFields
 * @param {{fields?: Object, nativeFields?: Object}} saved
 * @param {string} defaultChannel
 * @returns {{fields: Object, nativeFields: Object}}
 */
export function splitChannels(rows, subFields, saved, defaultChannel) {
    const channels = { fields: {}, nativeFields: {} };

    for (const [handle, row] of Object.entries(rows || {})) {
        channels[channelFor(handle, subFields, saved, defaultChannel)][handle] = row;
    }

    return channels;
}
