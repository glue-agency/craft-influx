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
 * @param {{onChange: (value: string) => void}} config Called after every
 * mutation with the serialized value (feeds v-model upward).
 */
export function useTokenSegments({ onChange }) {
    const segments = ref([]);

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
