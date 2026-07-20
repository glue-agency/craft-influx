import { ref } from 'vue';

/**
 * Segment model for the tokenized input: an alternating list of text and
 * token (chip) segments. Parse/serialize are pure exports — the riskiest
 * logic in the SPA, unit-tested in isolation; the composable wraps them
 * with the reactive list + the mutations the component drives.
 */

// Patterns chipped during parse:
//   - `{name}` — always chipped; SPA-owned placeholders.
//   - `$NAME`  — chipped only if NAME is in the known suggestion set.
//   - `@name`  — chipped only if @name is in the known suggestion set.
// The known-set guard keeps `email@example.com` and `cost $5` from
// accidentally turning their fragments into chips.
export const TOKEN_RE_SOURCE = '\\{[^{}\\s]+\\}|\\$[A-Za-z_][A-Za-z0-9_]*|@[A-Za-z][A-Za-z0-9_\\-]*';

let segIdCounter = 0;
const newSegId = () => ++segIdCounter;

/**
 * Split a raw string into segments. Always yields text segments around
 * (and between) every chip, so the component can place a cursor anywhere.
 *
 * @param {string} value
 * @param {Object<string, string>} kinds name → kind for every known token
 */
export function parseSegments(value, kinds) {
    const segments = [];
    let cursor = 0;
    const re = new RegExp(TOKEN_RE_SOURCE, 'g');
    let m;
    while ((m = re.exec(value)) !== null) {
        const matched = m[0];
        const isCurlyToken = matched[0] === '{';
        // `$ENV` / `@alias` only chip when the literal form is in the known
        // suggestion set — keeps user-typed garbage from looking like a
        // chip and keeps `email@example.com` text.
        if (!isCurlyToken && !(matched in kinds)) {
            continue;
        }
        segments.push({ id: newSegId(), type: 'text', value: value.slice(cursor, m.index) });
        segments.push({ id: newSegId(), type: 'token', name: matched, kind: kinds[matched] || 'custom' });
        cursor = m.index + matched.length;
    }
    segments.push({ id: newSegId(), type: 'text', value: value.slice(cursor) });
    return segments;
}

/** @param {Array} segments */
export function serializeSegments(segments) {
    return segments.map(s => (s.type === 'text' ? s.value : s.name)).join('');
}

/**
 * Absolute offset into the serialized value of a cursor position inside a
 * text segment — chips count their full `name` length. Cursor positions
 * beyond the segment clamp to its end; unknown segment ids clamp to the
 * end of the serialized value.
 *
 * @param {Array} segments
 * @param {number} segId Id of the text segment holding the cursor.
 * @param {number} cursorPos Cursor position within that segment's value.
 */
export function serializedOffsetOf(segments, segId, cursorPos) {
    let offset = 0;
    for (const seg of segments) {
        const length = seg.type === 'text' ? seg.value.length : seg.name.length;
        if (seg.type === 'text' && seg.id === segId) {
            return offset + Math.max(0, Math.min(cursorPos, length));
        }
        offset += length;
    }
    return offset;
}

/**
 * The text segment holding an absolute serialized offset, as
 * `{segId, cursorPos}` — the inverse of {@see serializedOffsetOf}, used to
 * restore the cursor across a re-parse (which rebuilds every segment id).
 * Offsets inside a chip land right AFTER it (start of the following text
 * segment); past-the-end offsets clamp to the end of the last text
 * segment. Null only for a (malformed) list without text segments.
 *
 * @param {Array} segments
 * @param {number} offset
 */
export function segmentAtOffset(segments, offset) {
    let remaining = Math.max(0, offset);
    let lastText = null;
    for (const seg of segments) {
        if (seg.type === 'text') {
            lastText = seg;
            if (remaining <= seg.value.length) {
                return { segId: seg.id, cursorPos: remaining };
            }
            remaining -= seg.value.length;
        } else {
            // Inside (or at the end of) the chip → snap to just after it;
            // the guaranteed following text segment picks it up at 0.
            remaining = Math.max(0, remaining - seg.name.length);
        }
    }
    return lastText ? { segId: lastText.id, cursorPos: lastText.value.length } : null;
}

/**
 * @param {{onChange: (value: string) => void}} config Called after every
 * mutation with the serialized value (feeds v-model upward).
 */
export function useTokenSegments({ onChange }) {
    // Never empty: parse always yields a trailing text segment, and the
    // initial state must honor that too — the component skips setFromValue()
    // when the model value is already '' and relies on a text segment being
    // there to render an input and land manual picks.
    const segments = ref(parseSegments('', {}));

    const serialize = () => serializeSegments(segments.value);
    const emitChange = () => onChange(serialize());

    const setFromValue = (value, kinds) => {
        segments.value = parseSegments(value || '', kinds);
    };

    /** Re-tint chips when the known-token set changes. */
    const recolor = (kinds) => {
        for (const seg of segments.value) {
            if (seg.type === 'token') {
                seg.kind = kinds[seg.name] || 'custom';
            }
        }
    };

    const indexOf = (segId) => segments.value.findIndex(s => s.id === segId);

    /** The chip immediately before/after a text segment, or null. */
    const tokenBefore = (segId) => {
        const prev = segments.value[indexOf(segId) - 1];
        return prev?.type === 'token' ? prev : null;
    };
    const tokenAfter = (segId) => {
        const next = segments.value[indexOf(segId) + 1];
        return next?.type === 'token' ? next : null;
    };

    /**
     * Remove a chip, joining its neighboring text segments. Returns where
     * the cursor should land ({segId, cursorPos}) or null when the id
     * wasn't a chip.
     */
    function removeToken(tokenId) {
        const idx = indexOf(tokenId);
        if (idx < 0 || segments.value[idx].type !== 'token') return null;
        const before = segments.value[idx - 1];
        const after = segments.value[idx + 1];
        const joinPoint = before?.value.length ?? 0;
        before.value = (before?.value ?? '') + (after?.value ?? '');
        segments.value.splice(idx, 2);
        emitChange();
        return { segId: before.id, cursorPos: joinPoint };
    }

    /**
     * Replace [start, end) of a text segment with a chip — both insertion
     * flows reduce to this: the trigger flow replaces the typed
     * `$que`-style range, the manual flow inserts at a zero-width range.
     * Returns the fresh trailing text segment to focus, or null.
     */
    function insertToken(segId, start, end, name, kind) {
        const idx = indexOf(segId);
        if (idx < 0 || segments.value[idx].type !== 'text') return null;
        const seg = segments.value[idx];

        const from = Math.max(0, Math.min(start, seg.value.length));
        const to = Math.max(from, Math.min(end, seg.value.length));
        const after = seg.value.slice(to);
        seg.value = seg.value.slice(0, from);

        const tokenSeg = { id: newSegId(), type: 'token', name, kind };
        const afterSeg = { id: newSegId(), type: 'text', value: after };
        segments.value.splice(idx + 1, 0, tokenSeg, afterSeg);
        emitChange();
        return { segId: afterSeg.id, cursorPos: 0 };
    }

    return { segments, setFromValue, serialize, recolor, tokenBefore, tokenAfter, removeToken, insertToken };
}
