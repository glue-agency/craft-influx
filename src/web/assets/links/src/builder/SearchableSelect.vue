<template>
    <div
        ref="root"
        class="influx-searchable-select"
        :class="{ open, disabled, 'has-value': hasValue }"
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
            <div class="influx-searchable-select-search">
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

            <ul
                v-if="filteredOptions.length"
                class="influx-searchable-select-options"
            >
                <li
                    v-for="(opt, idx) in filteredOptions"
                    :key="optionKey(opt, idx)"
                    role="option"
                    :class="{
                        highlighted: highlightedIndex === idx,
                        selected: isSelected(opt),
                        'is-empty': opt.value === '',
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
 * Option shape: { value: string, label: string }. An option with value=''
 * is treated as the "no selection" sentinel and rendered in muted italics
 * — useful for "— no mapping —" / "—" placeholders.
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
        disabled: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            open: false,
            query: '',
            highlightedIndex: 0,
        };
    },

    computed: {
        currentOption() {
            const v = this.normalize(this.modelValue);
            return this.options.find(o => this.normalize(o.value) === v) || null;
        },

        hasValue() {
            const v = this.normalize(this.modelValue);
            return v !== '' && v !== null && v !== undefined;
        },

        displayLabel() {
            if (this.currentOption && this.hasValue) return this.currentOption.label;
            // Saved value no longer in options (e.g. source node fell out of
            // the fetched sample). Show the raw value so the missing-mapping
            // badge still has a referent the user recognizes.
            if (this.hasValue) return this.normalize(this.modelValue);
            return this.placeholder || this.$t('Select…');
        },

        filteredOptions() {
            const q = this.query.trim().toLowerCase();
            if (!q) return this.options;
            return this.options.filter(o => {
                if (o.value === '') return false; // hide the "no selection" sentinel during search
                return (o.label || '').toLowerCase().includes(q)
                    || String(o.value || '').toLowerCase().includes(q);
            });
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
                    this.$refs.searchInput?.focus();
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
            this.open = !this.open;
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
            const menu = this.$refs.root?.querySelector('.influx-searchable-select-options');
            if (!menu) return;
            const row = menu.children[this.highlightedIndex];
            if (!row) return;
            const top = row.offsetTop;
            const bottom = top + row.offsetHeight;
            if (top < menu.scrollTop) {
                menu.scrollTop = top;
            } else if (bottom > menu.scrollTop + menu.clientHeight) {
                menu.scrollTop = bottom - menu.clientHeight;
            }
        },

        onTriggerKeydown(e) {
            if (this.disabled) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
                if (!this.open) {
                    e.preventDefault();
                    this.open = true;
                }
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
