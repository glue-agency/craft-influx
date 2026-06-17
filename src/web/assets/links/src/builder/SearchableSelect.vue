<template>
    <div
        ref="root"
        class="influx-searchable-select"
        :class="{ open, disabled, 'has-value': hasValue, 'drop-up': dropUp }"
    >
        <button
            type="button"
            class="influx-searchable-select-trigger"
            :class="{ active: open }"
            :disabled="disabled"
            :aria-expanded="open ? 'true' : 'false'"
            aria-haspopup="listbox"
            @click="toggle"
            @keydown="onTriggerKeydown"
        >
            <span class="value" :class="{ placeholder: !hasValue }">
                {{ displayLabel }}
            </span>
            <svg class="caret" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true">
                <path d="M2 3.75L5 6.75L8 3.75" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
        </button>

        <div
            v-if="open"
            class="influx-searchable-select-menu"
            role="listbox"
        >
            <div v-if="showSearch" class="influx-searchable-select-search">
                <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                    <circle cx="5" cy="5" r="3.25" stroke="currentColor" stroke-width="1.2" fill="none"/>
                    <path d="M7.5 7.5l3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                <input
                    ref="searchInput"
                    type="text"
                    v-model="query"
                    :placeholder="searchPlaceholder || $t('Search…')"
                    spellcheck="false"
                    autocomplete="off"
                    @keydown="onSearchKeydown"
                />
                <button
                    v-if="query"
                    type="button"
                    class="influx-searchable-select-clear-search"
                    :title="$t('Clear search')"
                    tabindex="-1"
                    @mousedown.prevent
                    @click="query = ''"
                >
                    <svg width="8" height="8" viewBox="0 0 8 8" aria-hidden="true">
                        <path d="M1 1l6 6M7 1l-6 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>

            <!-- Grouped options: token-picker visuals — h6 group headings and
                 kind-colored chip items — wrapped in one scroll region. -->
            <div v-if="filteredOptions.length && isGrouped" class="influx-searchable-select-scroll">
                <template v-for="(group, gi) in filteredGroups" :key="gi">
                    <h6 v-if="group.label">{{ group.label }}</h6>
                    <ul class="influx-searchable-select-options influx-token-group" :data-kind="group.kind || 'custom'">
                        <li
                            v-for="opt in group.options"
                            :key="optionKey(opt, opt._flatIdx)"
                            role="option"
                            :data-flat-idx="opt._flatIdx"
                            :class="{
                                highlighted: highlightedIndex === opt._flatIdx,
                                selected: isSelected(opt),
                                'is-empty': opt.value === '' && !emptyIsValue,
                            }"
                            :aria-selected="isSelected(opt) ? 'true' : 'false'"
                            @mousemove="highlightedIndex = opt._flatIdx"
                            @mousedown.prevent
                            @click="commit(opt)"
                        >
                            <!-- Chips only for kinded groups — label-less
                                 sentinel groups (— no mapping — etc.) render
                                 as plain rows. -->
                            <span
                                v-if="group.kind && opt.value !== ''"
                                class="influx-tokenized-chip-inline"
                                :data-kind="group.kind"
                                v-html="highlight(opt.label)"
                            ></span>
                            <span v-else class="label" v-html="highlight(opt.label)"></span>
                            <svg
                                v-if="isSelected(opt)"
                                class="check"
                                width="12"
                                height="12"
                                viewBox="0 0 12 12"
                                aria-hidden="true"
                            >
                                <path d="M2.5 6.25l2.4 2.4L9.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            </svg>
                        </li>
                    </ul>
                </template>
            </div>

            <ul
                v-else-if="filteredOptions.length"
                class="influx-searchable-select-options"
            >
                <li
                    v-for="(opt, idx) in filteredOptions"
                    :key="optionKey(opt, idx)"
                    role="option"
                    :data-flat-idx="idx"
                    :class="{
                        highlighted: highlightedIndex === idx,
                        selected: isSelected(opt),
                        'is-empty': opt.value === '' && !emptyIsValue,
                    }"
                    :aria-selected="isSelected(opt) ? 'true' : 'false'"
                    @mousemove="highlightedIndex = idx"
                    @mousedown.prevent
                    @click="commit(opt)"
                >
                    <span class="label" v-html="highlight(opt.label)"></span>
                    <svg
                        v-if="isSelected(opt)"
                        class="check"
                        width="12"
                        height="12"
                        viewBox="0 0 12 12"
                        aria-hidden="true"
                    >
                        <path d="M2.5 6.25l2.4 2.4L9.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                </li>
            </ul>

            <p v-else class="influx-searchable-select-empty">
                <template v-if="query">
                    {{ $t('No matches for') }} <code>{{ query }}</code>.
                </template>
                <template v-else>
                    {{ emptyLabel || $t('No options available.') }}
                </template>
            </p>
        </div>
    </div>
</template>

<script>
/**
 * Single-select dropdown with an embedded search input. Drop-in replacement
 * for native `<select>` wherever the option list is long enough that scrubbing
 * by eye gets old (source-node candidates from a fetched JSON sample, match-
 * attribute pickers, etc.).
 *
 * Mirrors the TokenizedInput picker's dropdown shell so users get the same
 * visual & keyboard ergonomics across the link builder:
 *   - ArrowDown / ArrowUp move the highlight (wraps).
 *   - Enter commits the highlighted option.
 *   - Escape closes the menu.
 *   - Click-outside closes the menu.
 *   - Backspace in an empty search box clears the current value.
 *
 * Option shapes:
 *   - flat:    [{value, label}] — the plain list rendering;
 *   - grouped: [{label, kind?, options: [{value, label}]}] — rendered with
 *     the token picker's visuals (h6 group headings, kind-colored chip
 *     items) while keeping the search + keyboard ergonomics. Labels may
 *     carry "(handle)" / "<value>" suffixes — they render as escaped text
 *     (see highlight()), so future value inlining is safe.
 *
 * An option with value='' is treated as the "no selection" sentinel and
 * rendered in muted italics — useful for "— no mapping —" / "—" placeholders.
 */
export default {
    name: 'SearchableSelect',

    props: {
        modelValue: { type: [String, Number], default: '' },
        options: { type: Array, default: () => [] },
        placeholder: { type: String, default: '' },
        searchPlaceholder: { type: String, default: '' },
        // Shown inside the dropdown when the option list is empty AND the
        // user hasn't typed a query (different from the "no matches" copy).
        emptyLabel: { type: String, default: '' },
        // Treat value='' as a real, labeled choice (e.g. the date format's
        // "Auto-detect" default) instead of the no-selection placeholder
        // sentinel: the trigger shows its label as a value, the option
        // renders like any other, and it stays findable during search.
        emptyIsValue: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        // Whether to show the search box. The caller decides (node pickers and
        // "Match by" selects want it; short fixed enums don't) — the component
        // doesn't guess from list length.
        searchable: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            open: false,
            query: '',
            highlightedIndex: 0,
            // Opens the menu above the trigger when the viewport has more
            // room there than below — measured on every open.
            dropUp: false,
        };
    },

    computed: {
        isGrouped() {
            const options = this.options || [];
            return options.length > 0 && Array.isArray(options[0]?.options);
        },

        /** Always-grouped view; flat input becomes one label-less group. */
        groups() {
            return this.isGrouped
                ? this.options
                : [{ label: null, kind: null, options: this.options || [] }];
        },

        allOptions() {
            return this.groups.flatMap(g => g.options || []);
        },

        // Caller-controlled via `searchable` — node pickers and "Match by"
        // selects opt in; short fixed enums leave it off. The component never
        // guesses from list length.
        showSearch() {
            return this.searchable;
        },

        currentOption() {
            const v = this.normalize(this.modelValue);
            return this.allOptions.find(o => this.normalize(o.value) === v) || null;
        },

        hasValue() {
            const v = this.normalize(this.modelValue);
            if (v !== '' && v !== null && v !== undefined) return true;
            return this.emptyIsValue && this.currentOption !== null;
        },

        displayLabel() {
            if (this.currentOption && this.hasValue) return this.currentOption.label;
            // Saved value no longer in options (e.g. source node fell out of
            // the fetched sample). Show the raw value so the missing-mapping
            // badge still has a referent the user recognizes.
            if (this.hasValue) return this.normalize(this.modelValue);
            return this.placeholder || this.$t('Select…');
        },

        /**
         * Groups with their options filtered by the query; empty groups are
         * dropped. Every surviving option gets a `_flatIdx` so the keyboard
         * highlight addresses one integer across group boundaries.
         */
        filteredGroups() {
            const q = this.query.trim().toLowerCase();
            const out = [];
            let flat = 0;
            for (const group of this.groups) {
                const options = (group.options || []).filter(o => {
                    if (!q) return true;
                    // Hide the "no selection" sentinel during search — unless
                    // '' is a real choice, then it matches by label like any.
                    if (o.value === '' && !this.emptyIsValue) return false;
                    return (o.label || '').toLowerCase().includes(q)
                        || String(o.value || '').toLowerCase().includes(q);
                });
                if (!options.length) continue;
                out.push({
                    label: group.label,
                    kind: group.kind,
                    options: options.map(o => ({ ...o, _flatIdx: flat++ })),
                });
            }
            return out;
        },

        filteredOptions() {
            return this.filteredGroups.flatMap(g => g.options);
        },
    },

    watch: {
        // Keep the highlight inside the visible range when the user types
        // and the list shrinks.
        filteredOptions() {
            if (this.highlightedIndex >= this.filteredOptions.length) {
                this.highlightedIndex = 0;
            }
        },

        open(isOpen) {
            if (isOpen) {
                this.query = '';
                // Land the highlight on the currently-selected option if it
                // survives the (empty) filter, otherwise on the first row.
                const selectedIdx = this.filteredOptions.findIndex(o => this.isSelected(o));
                this.highlightedIndex = selectedIdx >= 0 ? selectedIdx : 0;
                this.$nextTick(() => {
                    if (this.showSearch) this.$refs.searchInput?.focus();
                    this.scrollHighlightedIntoView();
                });
            }
        },
    },

    mounted() {
        document.addEventListener('mousedown', this.onDocumentMousedown);
        document.addEventListener('keydown', this.onDocumentKeydown);
    },

    beforeUnmount() {
        document.removeEventListener('mousedown', this.onDocumentMousedown);
        document.removeEventListener('keydown', this.onDocumentKeydown);
    },

    methods: {
        normalize(v) {
            if (v === undefined || v === null) return '';
            return String(v);
        },

        isSelected(opt) {
            return this.normalize(opt.value) === this.normalize(this.modelValue);
        },

        optionKey(opt, idx) {
            const v = this.normalize(opt.value);
            return v === '' ? `__empty_${idx}` : v;
        },

        toggle() {
            if (this.disabled) return;
            if (this.open) {
                this.close();
            } else {
                this.openMenu();
            }
        },

        openMenu() {
            this.updateDropDirection();
            this.open = true;
        },

        /**
         * Flip the menu above the trigger when the space below the viewport
         * edge can't fit it and there's more room above — otherwise a menu
         * near the page bottom stretches the document and drags the CP
         * sidebar along with it.
         */
        updateDropDirection() {
            const root = this.$refs.root;
            if (!root) {
                this.dropUp = false;
                return;
            }
            const rect = root.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const spaceAbove = rect.top;
            // The menu caps at 320px (links.css) + the 4px offset.
            this.dropUp = spaceBelow < 340 && spaceAbove > spaceBelow;
        },

        close() {
            this.open = false;
            this.query = '';
        },

        commit(opt) {
            this.$emit('update:modelValue', opt.value);
            this.close();
        },

        moveHighlight(delta) {
            const n = this.filteredOptions.length;
            if (!n) return;
            this.highlightedIndex = (this.highlightedIndex + delta + n) % n;
            this.$nextTick(this.scrollHighlightedIntoView);
        },

        scrollHighlightedIntoView() {
            const row = this.$refs.root?.querySelector(`li[data-flat-idx="${this.highlightedIndex}"]`);
            if (!row) return;
            // Grouped mode scrolls a wrapper around the per-group lists;
            // flat mode scrolls the single list itself.
            const scroller = row.closest('.influx-searchable-select-scroll')
                || row.closest('.influx-searchable-select-options');
            if (!scroller) return;
            const rowRect = row.getBoundingClientRect();
            const scrollerRect = scroller.getBoundingClientRect();
            if (rowRect.top < scrollerRect.top) {
                scroller.scrollTop += rowRect.top - scrollerRect.top;
            } else if (rowRect.bottom > scrollerRect.bottom) {
                scroller.scrollTop += rowRect.bottom - scrollerRect.bottom;
            }
        },

        onTriggerKeydown(e) {
            if (this.disabled) return;
            if (!this.open) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.openMenu();
                }
                return;
            }
            // No search box (short list) → focus stays on the trigger, so
            // it owns the menu keys the search input handles otherwise.
            if (this.showSearch) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.moveHighlight(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.moveHighlight(-1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const opt = this.filteredOptions[this.highlightedIndex];
                if (opt) this.commit(opt);
            }
        },

        onSearchKeydown(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.moveHighlight(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.moveHighlight(-1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const opt = this.filteredOptions[this.highlightedIndex];
                if (opt) this.commit(opt);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
            } else if (e.key === 'Backspace' && this.query === '' && this.hasValue) {
                // Quick-clear shortcut — empty search + backspace nukes the
                // selection. Matches how chip inputs behave on the boundary.
                e.preventDefault();
                this.$emit('update:modelValue', '');
                this.close();
            }
        },

        onDocumentMousedown(e) {
            if (!this.open) return;
            if (this.$refs.root && !this.$refs.root.contains(e.target)) {
                this.close();
            }
        },

        onDocumentKeydown(e) {
            if (!this.open) return;
            if (e.key === 'Escape') this.close();
        },

        /**
         * Wrap query matches in <mark> for visual scanning. Pure string ops —
         * the substring is plain text (the user's typed query), but we still
         * escape the surrounding label fragments to keep this safe to v-html.
         */
        highlight(label) {
            const text = String(label ?? '');
            const escape = (s) => s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            const q = this.query.trim();
            if (!q) return escape(text);

            const out = [];
            const haystack = text.toLowerCase();
            const needle = q.toLowerCase();
            let cursor = 0;
            let idx = haystack.indexOf(needle, cursor);
            while (idx !== -1) {
                out.push(escape(text.slice(cursor, idx)));
                out.push('<mark>' + escape(text.slice(idx, idx + needle.length)) + '</mark>');
                cursor = idx + needle.length;
                idx = haystack.indexOf(needle, cursor);
            }
            out.push(escape(text.slice(cursor)));
            return out.join('');
        },
    },
};
</script>

<style>
/* SearchableSelect dropdown - moved from links.css. Unscoped: the
   source-node dropdown is recolored cross-component by the missing-mapping
   rules that stay global in links.css. */
/* ---------------------------------------------------------------------
   SearchableSelect — single-select dropdown with embedded search. Visual
   vocabulary borrowed from TokenizedInput's picker: same border, same
   blue hover accent, same dropdown shadow. The trigger button mimics
   Craft's native `.select` chrome (gray pill with caret) so it slots in
   alongside the rest of the form.
--------------------------------------------------------------------- */
.influx-searchable-select {
    position: relative;
    width: 100%;
}

.influx-searchable-select-trigger {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 34px;
    padding: 6px 28px 6px 10px;
    background: #fff;
    border: 1px solid hsla(212deg, 25%, 50%, 0.25);
    border-radius: 5px;
    color: #1f2937;
    text-align: left;
    cursor: pointer;
    font: inherit;
    line-height: 1.4;
    position: relative;
    transition: border-color 0.1s ease, box-shadow 0.1s ease;
}
.influx-searchable-select-trigger:hover {
    border-color: hsla(212deg, 25%, 40%, 0.4);
}
.influx-searchable-select-trigger:focus-visible,
.influx-searchable-select-trigger.active {
    outline: none;
    border-color: hsl(208deg, 100%, 55%);
    box-shadow: 0 0 0 2px hsla(208deg, 100%, 55%, 0.18);
}
.influx-searchable-select-trigger:disabled {
    background: #f7f9fc;
    color: #9aa4ad;
    cursor: not-allowed;
}
.influx-searchable-select-trigger .value {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.influx-searchable-select-trigger .value.placeholder {
    color: #9aa4ad;
}
.influx-searchable-select-trigger .caret {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    color: #6b7280;
    transition: transform 0.15s ease, color 0.1s ease;
    pointer-events: none;
}
.influx-searchable-select.open .influx-searchable-select-trigger .caret {
    transform: translateY(-50%) rotate(180deg);
    color: hsl(208deg, 100%, 38%);
}

.influx-searchable-select-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 200;
    background: #fff;
    border: 1px solid #d7dfe7;
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba(20, 30, 50, 0.16), 0 2px 6px rgba(20, 30, 50, 0.06);
    padding: 6px 0 4px;
    display: flex;
    flex-direction: column;
    max-height: 320px;
    overflow: hidden;
}

/* Search box at the top — same chrome as TokenizedInput's manual picker
   so the two pickers feel like siblings. */
.influx-searchable-select-search {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 2px 8px 6px;
    padding: 6px 8px;
    background: hsl(212deg, 25%, 96%);
    border: 1px solid hsla(212deg, 25%, 50%, 0.18);
    border-radius: 4px;
    color: #6b7280;
}
.influx-searchable-select-search input {
    flex: 1 1 auto;
    min-width: 0;
    border: 0;
    background: transparent;
    outline: none;
    padding: 0;
    font: inherit;
    color: #1f2937;
}
.influx-searchable-select-search input::placeholder {
    color: #9aa4ad;
}
.influx-searchable-select-search input:focus {
    box-shadow: none;
}
.influx-searchable-select-clear-search {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    opacity: 0.7;
    transition: opacity 0.1s ease, background-color 0.1s ease;
}
.influx-searchable-select-clear-search:hover {
    opacity: 1;
    background: rgba(0, 0, 0, 0.08);
}

.influx-searchable-select-options {
    list-style: none;
    margin: 0;
    padding: 0 4px 2px;
    overflow-y: auto;
    flex: 1 1 auto;
}
.influx-searchable-select-options li {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    color: #1f2937;
    transition: background-color 0.08s ease;
}
.influx-searchable-select-options li .label {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.influx-searchable-select-options li .label mark {
    background: hsl(48deg, 100%, 80%);
    color: inherit;
    padding: 0 1px;
    border-radius: 2px;
}
.influx-searchable-select-options li .check {
    flex: 0 0 auto;
    color: hsl(208deg, 100%, 42%);
}
.influx-searchable-select-options li.is-empty .label {
    color: #6b7280;
    font-style: italic;
}
.influx-searchable-select-options li.selected {
    color: hsl(208deg, 100%, 28%);
    font-weight: 600;
}
.influx-searchable-select-options li.highlighted,
.influx-searchable-select-options li:hover {
    background: hsl(208deg, 100%, 96%);
}
.influx-searchable-select-options li.selected.highlighted,
.influx-searchable-select-options li.selected:hover {
    background: hsl(208deg, 100%, 92%);
}

.influx-searchable-select-empty {
    margin: 4px 14px 8px;
    color: #6b7280;
    font-size: 12px;
}
.influx-searchable-select-empty code {
    background: #ececef;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 11px;
}
/* Grouped SearchableSelect (relation/author "Match by"): one scroll region
   wrapping the per-kind option groups, with token-picker-style headings.
   The per-group lists must not scroll individually. */
.influx-searchable-select-scroll {
    overflow-y: auto;
    flex: 1 1 auto;
    padding-bottom: 2px;
}
.influx-searchable-select-scroll .influx-searchable-select-options {
    overflow-y: visible;
    flex: none;
}
.influx-searchable-select-menu h6 {
    margin: 8px 14px 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #8b95a3;
}
.influx-searchable-select-scroll h6:first-child {
    margin-top: 4px;
}
/* Drop-up: when the viewport has no room below the trigger, the menu
   anchors above it instead of stretching the document (which dragged the
   CP sidebar along). The direction is measured per open in
   SearchableSelect.updateDropDirection(). */
.influx-searchable-select.drop-up .influx-searchable-select-menu {
    top: auto;
    bottom: calc(100% + 4px);
}
</style>
