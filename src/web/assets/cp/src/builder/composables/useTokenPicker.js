import { computed, ref } from 'vue';
import { useListHighlight } from './useListHighlight.js';

/**
 * The picker state machine for the tokenized input — everything about
 * *which* items to show and *which one* is highlighted (the highlight
 * mechanics delegate to {@see useListHighlight}), with no DOM knowledge.
 * Two operating modes:
 *
 *   - Triggered: the user types `$`, `@`, or `{` inside a text segment.
 *     The text from the trigger char to the cursor is a live query —
 *     IDE-autocomplete style. Selecting REPLACES the trigger range.
 *   - Manual: the `+` button. A dedicated search input filters items.
 */

export const TRIGGER_CHARS = ['$', '@', '{'];

// Characters that, when typed after a trigger, abort the trigger. Keeps
// `$API_BASE` triggered through underscores / digits / dots but cancels
// when the user moves on to a separator like `/` or whitespace.
export const TRIGGER_TERMINATOR_RE = /[\s/?#&=\\]/;

/**
 * @param {{groups: () => Array, segmentValue: (segId: number) => ?string}} config
 * `groups` reads the live token groups; `segmentValue` resolves a segment's
 * current text so the triggered query can slice it.
 */
export function useTokenPicker({ groups, segmentValue }) {
    const manualOpen = ref(false);
    const searchQuery = ref('');
    // `startPos` is the index of the trigger CHARACTER in the segment, so
    // the query is segment.value.slice(startPos, cursorPos).
    const triggerState = ref(null);
    const cursorPos = ref(0);

    const pickerVisible = computed(() => manualOpen.value || triggerState.value !== null);

    // The string the picker filters on. Triggered mode reads it live off
    // the segment value so the dropdown narrows as the user types.
    const effectiveQuery = computed(() => {
        if (triggerState.value) {
            const value = segmentValue(triggerState.value.segId);
            if (value == null) return '';
            return value.slice(triggerState.value.startPos, cursorPos.value);
        }
        return searchQuery.value;
    });

    // Filter groups by query (case-insensitive substring match on name or
    // hint). Each surviving group keeps a `_gid` for `:key` stability and
    // each surviving item gets a `_flatIdx` so keyboard nav can address
    // items by a single integer.
    const filteredGroups = computed(() => {
        const q = (effectiveQuery.value || '').toLowerCase();
        const out = [];
        let flat = 0;
        const all = groups() || [];
        for (let gi = 0; gi < all.length; gi++) {
            const group = all[gi];
            const data = (group.data || []).filter(item => {
                if (!q) return true;
                return item.name.toLowerCase().includes(q)
                    || (item.hint || '').toLowerCase().includes(q);
            });
            if (!data.length) continue;
            out.push({
                _gid: `${gi}-${group.kind || ''}`,
                kind: group.kind,
                label: group.label,
                data: data.map(item => ({ ...item, _flatIdx: flat++ })),
            });
        }
        return out;
    });

    const flatItems = computed(() => filteredGroups.value.flatMap(g => g.data));

    // Highlight index + wrap-around movement + the shared menu-key
    // dispatcher, clamped to the filtered list by the composable's own
    // shrink watcher.
    const {
        highlightedIndex, moveHighlight, reset: resetHighlight,
        onListKeydown, scrollHighlightedIntoView,
    } = useListHighlight({ count: () => flatItems.value.length });

    const highlightedItem = computed(() => flatItems.value[highlightedIndex.value] || null);

    /** Cursor bookkeeping without trigger side effects (text input flow). */
    function setCursor(pos) {
        cursorPos.value = pos;
    }

    /**
     * Cursor bookkeeping from clicks/keyups — also closes the trigger when
     * the cursor moved before its anchor (user clicked back into the URL).
     */
    function trackCursor(segId, pos) {
        cursorPos.value = pos;
        if (triggerState.value && triggerState.value.segId === segId && pos < triggerState.value.startPos) {
            clearTrigger();
        }
    }

    /**
     * Detect a trigger char freshly typed at the cursor. Compares previous
     * vs next to confirm exactly one char was inserted (so pasting a `$`
     * mid-string doesn't trigger), and requires a separator before the
     * trigger so mid-word `{` in a URL fragment stays inert.
     */
    function maybeOpenTrigger(segId, prev, next, cursor) {
        if (triggerState.value) return;
        if (next.length <= prev.length) return; // user deleted; no trigger

        const justTyped = next[cursor - 1];
        if (!TRIGGER_CHARS.includes(justTyped)) return;
        const reconstructed = next.slice(0, cursor - 1) + next.slice(cursor);
        if (reconstructed !== prev) return; // multiple edits; bail

        const before = next[cursor - 2];
        if (before !== undefined && !TRIGGER_TERMINATOR_RE.test(before)) {
            return; // typing mid-word; don't auto-trigger
        }

        triggerState.value = { segId, startPos: cursor - 1 };
        resetHighlight();
    }

    /**
     * Close the trigger when the new text invalidates it: the trigger char
     * was deleted, the cursor moved before it, or a terminator was typed
     * inside the query range.
     */
    function maybeCloseTrigger(segId, value, cursor) {
        if (!triggerState.value || triggerState.value.segId !== segId) return;
        const startPos = triggerState.value.startPos;
        if (cursor <= startPos) {
            clearTrigger();
            return;
        }
        const range = value.slice(startPos, cursor);
        if (range.length === 0) {
            clearTrigger();
            return;
        }
        for (let i = 1; i < range.length; i++) {
            if (TRIGGER_TERMINATOR_RE.test(range[i])) {
                clearTrigger();
                return;
            }
        }
    }

    function clearTrigger() {
        triggerState.value = null;
        resetHighlight();
    }

    function openManual() {
        searchQuery.value = '';
        resetHighlight();
        manualOpen.value = true;
    }

    function closeManual() {
        manualOpen.value = false;
        searchQuery.value = '';
    }

    function toggleManual() {
        manualOpen.value ? closeManual() : openManual();
    }

    return {
        manualOpen, searchQuery, triggerState, cursorPos, highlightedIndex,
        pickerVisible, effectiveQuery, filteredGroups, flatItems, highlightedItem,
        setCursor, trackCursor, maybeOpenTrigger, maybeCloseTrigger, clearTrigger,
        openManual, closeManual, toggleManual, moveHighlight,
        onListKeydown, scrollHighlightedIntoView,
    };
}
