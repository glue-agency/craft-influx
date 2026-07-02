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
            <div v-if="filteredOptions.length && isGrouped" class="influx-searchable-select-scroll" data-influx-scroll>
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
                data-influx-scroll
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

<script setup>
/**
 * Single-select dropdown with an embedded search input. Drop-in replacement
 * for native `<select>` wherever the option list is long enough that scrubbing
 * by eye gets old (source-node candidates from a fetched JSON sample, match-
 * attribute pickers, etc.).
 *
 * Mirrors the TokenizedInput picker's dropdown shell so users get the same
 * visual & keyboard ergonomics across the link builder — the shared
 * mechanics live in {@see useDropdown} (open/close, drop direction,
 * document-level dismissal) and {@see useListHighlight} (arrow keys,
 * scroll-follow):
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
import { computed, nextTick, ref, watch } from 'vue';
import { t } from '../lib/installT.js';
import { useDropdown } from './composables/useDropdown.js';
import { useListHighlight } from './composables/useListHighlight.js';

defineOptions({ name: 'SearchableSelect' });

const props = defineProps({
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
});

const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const searchInput = ref(null);
const query = ref('');

// Open/close state, drop-up measurement (the menu caps at 320px in the
// styles + the 4px offset → 340 threshold), and document-level dismissal
// all live in the composable; every close also resets the query.
const { open, dropUp, openMenu, close, toggle: toggleMenu } = useDropdown({
    root: () => root.value,
    onClose: () => { query.value = ''; },
});

const isGrouped = computed(() => {
    const options = props.options || [];

    return options.length > 0 && Array.isArray(options[0]?.options);
});

/** Always-grouped view; flat input becomes one label-less group. */
const groups = computed(() => (isGrouped.value
    ? props.options
    : [{ label: null, kind: null, options: props.options || [] }]));

const allOptions = computed(() => groups.value.flatMap(g => g.options || []));

// Caller-controlled via `searchable` — node pickers and "Match by"
// selects opt in; short fixed enums leave it off. The component never
// guesses from list length.
const showSearch = computed(() => props.searchable);

const currentOption = computed(() => {
    const v = normalize(props.modelValue);

    return allOptions.value.find(o => normalize(o.value) === v) || null;
});

const hasValue = computed(() => {
    const v = normalize(props.modelValue);
    if (v !== '' && v !== null && v !== undefined) return true;

    return props.emptyIsValue && currentOption.value !== null;
});

const displayLabel = computed(() => {
    if (currentOption.value && hasValue.value) return currentOption.value.label;
    // Saved value no longer in options (e.g. source node fell out of
    // the fetched sample). Show the raw value so the missing-mapping
    // badge still has a referent the user recognizes.
    if (hasValue.value) return normalize(props.modelValue);

    return props.placeholder || t('Select…');
});

/**
 * Groups with their options filtered by the query; empty groups are
 * dropped. Every surviving option gets a `_flatIdx` so the keyboard
 * highlight addresses one integer across group boundaries.
 */
const filteredGroups = computed(() => {
    const q = query.value.trim().toLowerCase();
    const out = [];
    let flat = 0;

    for (const group of groups.value) {
        const options = (group.options || []).filter(o => {
            if (!q) return true;
            // Hide the "no selection" sentinel during search — unless
            // '' is a real choice, then it matches by label like any.
            if (o.value === '' && !props.emptyIsValue) return false;

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
});

const filteredOptions = computed(() => filteredGroups.value.flatMap(g => g.options));

// Highlight index, wrap-around arrows, and the shared menu-key dispatcher;
// the composable clamps the index when typing shrinks the list.
const {
    highlightedIndex, reset: resetHighlight,
    onListKeydown, scrollHighlightedIntoView,
} = useListHighlight({ count: () => filteredOptions.value.length });

watch(open, (isOpen) => {
    if (!isOpen) return;
    query.value = '';
    // Land the highlight on the currently-selected option if it
    // survives the (empty) filter, otherwise on the first row.
    const selectedIdx = filteredOptions.value.findIndex(o => isSelected(o));
    resetHighlight(selectedIdx >= 0 ? selectedIdx : 0);
    nextTick(() => {
        if (showSearch.value) searchInput.value?.focus();
        scrollHighlightedIntoView(root.value);
    });
});

function normalize(v) {
    if (v === undefined || v === null) return '';

    return String(v);
}

function isSelected(opt) {
    return normalize(opt.value) === normalize(props.modelValue);
}

function optionKey(opt, idx) {
    const v = normalize(opt.value);

    return v === '' ? `__empty_${idx}` : v;
}

function toggle() {
    if (props.disabled) return;
    toggleMenu();
}

function commit(opt) {
    emit('update:modelValue', opt.value);
    close();
}

/** Enter commits whatever the highlight sits on — nothing when empty. */
function commitHighlighted() {
    const opt = filteredOptions.value[highlightedIndex.value];
    if (opt) commit(opt);
}

function onTriggerKeydown(e) {
    if (props.disabled) return;

    if (!open.value) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openMenu();
        }

        return;
    }
    // No search box (short list) → focus stays on the trigger, so
    // it owns the menu keys the search input handles otherwise.
    if (showSearch.value) return;

    if (onListKeydown(e, { commit: commitHighlighted, close })) {
        nextTick(() => scrollHighlightedIntoView(root.value));
    }
}

function onSearchKeydown(e) {
    if (onListKeydown(e, { commit: commitHighlighted, close })) {
        nextTick(() => scrollHighlightedIntoView(root.value));

        return;
    }

    if (e.key === 'Backspace' && query.value === '' && hasValue.value) {
        // Quick-clear shortcut — empty search + backspace nukes the
        // selection. Matches how chip inputs behave on the boundary.
        e.preventDefault();
        emit('update:modelValue', '');
        close();
    }
}

/**
 * Wrap query matches in <mark> for visual scanning. Pure string ops —
 * the substring is plain text (the user's typed query), but we still
 * escape the surrounding label fragments to keep this safe to v-html.
 */
function highlight(label) {
    const text = String(label ?? '');
    const escape = (s) => s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const q = query.value.trim();
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
}
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
   useDropdown's updateDropDirection(). */
.influx-searchable-select.drop-up .influx-searchable-select-menu {
    top: auto;
    bottom: calc(100% + 4px);
}
</style>
