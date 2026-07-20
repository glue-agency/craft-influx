import { computed, ref, watch } from 'vue';

/**
 * Keyboard-highlight state for a flat option list: one integer index that
 * arrow keys move (wrapping at both ends), a shared keydown dispatcher, and
 * a scroll-follow helper. No filtering and no open/close state — callers
 * own their list shape (SearchableSelect's `_flatIdx`-stamped options, the
 * token picker's flat items) and pass its live length via `count`.
 */

/**
 * @param {{count: () => number}} config `count` reads the live length of
 * the flat (filtered) list the highlight addresses.
 */
export function useListHighlight({ count }) {
    // Index into the flat filtered list, set by hover or arrow keys.
    const highlightedIndex = ref(0);

    const total = computed(() => count() || 0);

    // Keep the highlight inside the visible range when the list shrinks
    // (the user typed and the filter narrowed).
    watch(total, (n) => {
        if (highlightedIndex.value >= n) {
            highlightedIndex.value = 0;
        }
    });

    /** Wraps around the ends to ease quick scrubbing in long lists. */
    function moveHighlight(delta) {
        const n = total.value;
        if (n === 0) return;
        highlightedIndex.value = (highlightedIndex.value + delta + n) % n;
    }

    /** Re-seed the highlight (list opened, trigger armed, …). */
    function reset(idx = 0) {
        highlightedIndex.value = idx;
    }

    /**
     * The four menu keys every picker shares. Returns true when the key was
     * handled so callers can chain their own extras (Backspace-clears-value,
     * chip-eating at segment boundaries) on the falsy path.
     *
     * @param {KeyboardEvent} e
     * @param {{commit: () => void, close: () => void}} handlers
     * @returns {boolean}
     */
    function onListKeydown(e, { commit, close }) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            moveHighlight(1);

            return true;
        }

        if (e.key === 'ArrowUp') {
            e.preventDefault();
            moveHighlight(-1);

            return true;
        }

        if (e.key === 'Enter') {
            e.preventDefault();
            commit();

            return true;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            close();

            return true;
        }

        return false;
    }

    /**
     * Nudge the nearest `[data-influx-scroll]` container so the highlighted
     * row (`[data-flat-idx]`) stays visible. Call from a nextTick after the
     * highlight moved so the row classes have re-rendered.
     *
     * @param {?HTMLElement} rootEl Element to query the row inside.
     */
    function scrollHighlightedIntoView(rootEl) {
        const row = rootEl?.querySelector(`[data-flat-idx="${highlightedIndex.value}"]`);
        if (!row) return;
        const scroller = row.closest('[data-influx-scroll]');
        if (!scroller) return;
        const rowRect = row.getBoundingClientRect();
        const scrollerRect = scroller.getBoundingClientRect();

        if (rowRect.top < scrollerRect.top) {
            scroller.scrollTop += rowRect.top - scrollerRect.top;
        } else if (rowRect.bottom > scrollerRect.bottom) {
            scroller.scrollTop += rowRect.bottom - scrollerRect.bottom;
        }
    }

    return {
        highlightedIndex,
        moveHighlight, reset, onListKeydown, scrollHighlightedIntoView,
    };
}
