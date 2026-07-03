<template>
    <div
        ref="rootEl"
        class="influx-tokenized-input"
        :class="{ disabled, 'all-selected': allSelected }"
        @click="onContainerClick"
        @focusout="onFocusOut"
        @copy="onCopy"
        @cut="onCut"
    >
        <div class="influx-tokenized-segments">
            <template v-for="seg in segments" :key="seg.id">
                <input
                    v-if="seg.type === 'text'"
                    :ref="el => setTextRef(seg.id, el)"
                    type="text"
                    class="influx-tokenized-text"
                    :placeholder="seg === firstTextSegment && isEmpty ? placeholder : ''"
                    :value="seg.value"
                    :disabled="disabled"
                    spellcheck="false"
                    autocomplete="off"
                    autocapitalize="off"
                    autocorrect="off"
                    @input="onTextInput(seg, $event)"
                    @keydown="onTextKeydown(seg, $event)"
                    @paste="onPaste(seg, $event)"
                    @keyup="picker.trackCursor(seg.id, $event.target.selectionStart ?? 0); lastFocusedSegId = seg.id"
                    @mouseup="picker.trackCursor(seg.id, $event.target.selectionStart ?? 0); lastFocusedSegId = seg.id"
                    @focus="onTextFocus(seg, $event)"
                />
                <token-chip
                    v-else
                    :name="seg.name"
                    :kind="seg.kind"
                    :disabled="disabled"
                    @remove="onRemoveToken(seg.id)"
                />
            </template>
        </div>

        <div
            v-if="!disabled && hasGroups"
            ref="pickerWrap"
            class="influx-tokenized-picker-wrap"
        >
            <button
                type="button"
                class="influx-tokenized-picker-btn"
                :class="{ active: picker.pickerVisible.value }"
                :aria-expanded="picker.pickerVisible.value ? 'true' : 'false'"
                aria-haspopup="menu"
                :title="pickerLabel"
                @mousedown.prevent
                @click="picker.toggleManual"
            >
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                    <path d="M7 2v10M2 7h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </button>

            <token-picker-menu
                v-if="picker.pickerVisible.value"
                :filtered-groups="picker.filteredGroups.value"
                :highlighted-index="picker.highlightedIndex.value"
                :effective-query="picker.effectiveQuery.value"
                :show-search="!picker.triggerState.value"
                :search-query="picker.searchQuery.value"
                @select="commitSelection"
                @highlight="picker.highlightedIndex.value = $event"
                @update:search-query="picker.searchQuery.value = $event"
                @search-keydown="onSearchKeydown"
            />
        </div>
    </div>
</template>

<script setup>
/**
 * Tokenized text input with inline chips + IDE-style trigger picker. This
 * component is the DOM glue only — segment parse/serialize/mutations live
 * in {@see useTokenSegments}, the picker state machine (trigger detection,
 * filtering, highlight) in {@see useTokenPicker}, and the chip / dropdown
 * markup in TokenChip / TokenPickerMenu.
 *
 * Keyboard nav (active whenever the picker is visible):
 *   - ArrowDown / ArrowUp: move the highlight across the flat filtered list
 *                          (the menu scrolls to follow it).
 *   - Enter:               commit the highlighted item.
 *   - Escape:              close the picker (clears trigger state too).
 *
 * Boundary edits (always on):
 *   - Backspace at position 0 of a text segment removes the preceding chip.
 *   - Delete at end-of-text removes the following chip.
 *
 * Select-all + clipboard (native selection can't span the separate segment
 * inputs, and chips aren't text — so the component owns these):
 *   - Cmd/Ctrl+A selects the WHOLE value component-wide; Escape, caret
 *     moves, clicks, and focus changes drop the selection.
 *   - Copy/Cut while all-selected write the full serialized value; Cut
 *     also clears it. Typing replaces it, like over a native selection.
 *   - Paste splices into the serialized value and re-parses it, so pasted
 *     `$VAR` / `@alias` / `{placeholder}` tokens chip-ify.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TokenChip from './components/TokenChip.vue';
import TokenPickerMenu from './components/TokenPickerMenu.vue';
import { useTokenPicker } from './composables/useTokenPicker.js';
import { segmentAtOffset, serializedOffsetOf, useTokenSegments } from './composables/useTokenSegments.js';

const props = defineProps({
    modelValue:  { type: String, default: '' },
    tokenGroups: { type: Array, default: () => [] },
    placeholder: { type: String, default: '' },
    pickerLabel: { type: String, default: 'Insert token' },
    disabled:    { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'blur']);

// Map of `name → kind` covering every known suggestion. Used by parse to
// decide whether a `$X` / `@y` match should chip, and to color chips.
const tokenKinds = computed(() => {
    const out = {};
    for (const group of props.tokenGroups) {
        for (const item of (group.data || [])) {
            out[item.name] = group.kind || 'custom';
        }
    }
    return out;
});

const {
    segments, setFromValue, serialize, recolor,
    tokenBefore, tokenAfter, removeToken, insertToken,
} = useTokenSegments({
    onChange: (value) => emit('update:modelValue', value),
});

const picker = useTokenPicker({
    groups: () => props.tokenGroups,
    segmentValue: (segId) => segments.value.find(s => s.id === segId)?.value ?? null,
});

watch(() => props.modelValue, (next) => {
    if (serialize() === (next || '')) return;
    setFromValue(next, tokenKinds.value);
}, { immediate: true });

// Watch the derived name→kind map rather than deep-watching tokenGroups:
// the store only ever replaces suggestions wholesale, and this skips the
// hint-only churn a deep traversal would re-trigger on.
watch(tokenKinds, (kinds) => recolor(kinds));

const firstTextSegment = computed(() => segments.value.find(s => s.type === 'text') || null);

const isEmpty = computed(() =>
    segments.value.length === 1
    && segments.value[0].type === 'text'
    && segments.value[0].value === '');

const hasGroups = computed(() => (props.tokenGroups || []).some(g => (g.data || []).length > 0));

// ---- DOM refs / focus ----

const rootEl = ref(null);
const pickerWrap = ref(null);
const textRefs = {};
const lastFocusedSegId = ref(null);

// Component-level "everything is selected" state (Cmd/Ctrl+A) — native
// selection can't span the segment inputs, so this flag stands in for it
// and the copy/cut/paste/typing handlers below honor it.
const allSelected = ref(false);

function setTextRef(id, el) {
    if (el) {
        textRefs[id] = el;
    } else {
        delete textRefs[id];
    }
}

function focusSegment(id, cursorPos) {
    nextTick(() => {
        const el = textRefs[id];
        if (!el) return;
        el.focus();
        const pos = Math.max(0, Math.min(cursorPos ?? 0, el.value.length));
        el.setSelectionRange(pos, pos);
        lastFocusedSegId.value = id;
        picker.setCursor(pos);
    });
}

/**
 * Component-level blur: the input is many text segments, so a single
 * segment's focusout only counts when focus actually left the component
 * (segment-to-segment hops and picker clicks keep it inside).
 */
function onFocusOut(e) {
    if (!rootEl.value?.contains(e.relatedTarget)) {
        allSelected.value = false;
        emit('blur');
    }
}

function onContainerClick(e) {
    // Any click inside the component (segments, chips, picker) collapses a
    // component-level selection — same as clicking into a native selection.
    allSelected.value = false;
    if (e.target !== rootEl.value && !e.target.matches('.influx-tokenized-segments')) return;
    const last = segments.value[segments.value.length - 1];
    if (last?.type === 'text') {
        focusSegment(last.id, last.value.length);
    }
}

function onTextFocus(seg, e) {
    allSelected.value = false;
    lastFocusedSegId.value = seg.id;
    picker.setCursor(e.target.selectionStart ?? 0);
    // Switching segments cancels any in-flight trigger — the trigger lives
    // in the segment where it was opened.
    if (picker.triggerState.value && picker.triggerState.value.segId !== seg.id) {
        picker.clearTrigger();
    }
}

// ---- text input events ----

function onTextInput(seg, e) {
    const previous = seg.value;
    const next = e.target.value;
    const cursor = e.target.selectionStart ?? next.length;

    seg.value = next;
    picker.setCursor(cursor);
    lastFocusedSegId.value = seg.id;

    if (!picker.triggerState.value) {
        picker.maybeOpenTrigger(seg.id, previous, next, cursor);
    } else if (picker.triggerState.value.segId === seg.id) {
        picker.maybeCloseTrigger(seg.id, seg.value, cursor);
    }

    emit('update:modelValue', serialize());
}

function onTextKeydown(seg, e) {
    // Cmd/Ctrl+A: a URL-style field's expected select-all is the WHOLE
    // value, not one segment — swap the native behavior for the component-
    // level selection (which supersedes any in-flight picker).
    if ((e.metaKey || e.ctrlKey) && !e.altKey && e.key?.toLowerCase() === 'a') {
        e.preventDefault();
        picker.clearTrigger();
        picker.closeManual();
        allSelected.value = true;
        return;
    }

    if (allSelected.value && onAllSelectedKeydown(e)) {
        return;
    }

    // Picker-driven keys while a trigger is active in this segment. Escape
    // only cancels the trigger here — the manual picker has its own search
    // input, so it never routes through a text segment.
    if (picker.pickerVisible.value && picker.triggerState.value?.segId === seg.id) {
        if (picker.onListKeydown(e, { commit: commitHighlighted, close: picker.clearTrigger })) {
            nextTick(() => picker.scrollHighlightedIntoView(pickerWrap.value));
            return;
        }
    }

    // Boundary chip-eat keys (always on).
    if (e.key === 'Backspace') {
        if (e.target.selectionStart !== 0 || e.target.selectionEnd !== 0) return;
        const prev = tokenBefore(seg.id);
        if (!prev) return;
        e.preventDefault();
        onRemoveToken(prev.id);
    } else if (e.key === 'Delete') {
        if (e.target.selectionStart !== seg.value.length || e.target.selectionEnd !== seg.value.length) return;
        const next = tokenAfter(seg.id);
        if (!next) return;
        e.preventDefault();
        onRemoveToken(next.id);
    }
}

function onSearchKeydown(e) {
    // Manual mode: Escape closes the whole manual picker (vs. the trigger
    // flow above, which only clears the trigger).
    if (picker.onListKeydown(e, { commit: commitHighlighted, close: picker.closeManual })) {
        nextTick(() => picker.scrollHighlightedIntoView(pickerWrap.value));
    }
}

/** Enter commits whatever the highlight sits on — nothing when empty. */
function commitHighlighted() {
    if (picker.highlightedItem.value) {
        commitSelection(picker.highlightedItem.value);
    }
}

// ---- select-all + clipboard ----

/**
 * Keys while the whole value is selected. Returns true when the key was
 * consumed (the caller then skips picker routing and boundary chip-eats).
 */
function onAllSelectedKeydown(e) {
    // Modified keys fall through: Cmd+C/X/V land on the copy/cut/paste
    // handlers below, everything else keeps its native meaning.
    if (e.metaKey || e.ctrlKey || e.altKey) return false;

    if (e.key === 'Escape') {
        allSelected.value = false;
        return true;
    }
    if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) {
        // Caret moves drop the selection; the native default proceeds.
        allSelected.value = false;
        return false;
    }
    if (e.key === 'Backspace' || e.key === 'Delete') {
        e.preventDefault();
        clearAll();
        return true;
    }
    if (e.key?.length === 1) {
        // A printable char replaces the whole value — exactly like typing
        // over a native full selection.
        e.preventDefault();
        replaceAll(e.key);
        return true;
    }
    return false;
}

/** Replace the whole value with a single typed character. */
function replaceAll(char) {
    allSelected.value = false;
    setFromValue(char, tokenKinds.value);
    emit('update:modelValue', serialize());

    // One char can never parse into a chip, so this is THE text segment.
    const seg = segments.value[segments.value.length - 1];
    picker.setCursor(char.length);
    // Typing `$` / `@` / `{` over the selection starts a trigger, just as
    // it would after clearing the field and typing it (no-op otherwise).
    picker.maybeOpenTrigger(seg.id, '', char, char.length);
    focusSegment(seg.id, char.length);
}

/** Clear the whole value (all-selected Backspace/Delete, and cut). */
function clearAll() {
    allSelected.value = false;
    setFromValue('', tokenKinds.value);
    emit('update:modelValue', '');
    focusSegment(segments.value[0].id, 0);
}

/**
 * Copy/cut bubble up here from whichever segment input holds focus. Only
 * the all-selected state is intercepted — a partial selection inside one
 * segment is native text and copies fine on its own.
 */
function onCopy(e) {
    if (!allSelected.value || !e.clipboardData) return;
    e.preventDefault();
    e.clipboardData.setData('text/plain', serialize());
}

function onCut(e) {
    if (!allSelected.value || !e.clipboardData) return;
    e.preventDefault();
    e.clipboardData.setData('text/plain', serialize());
    clearAll();
}

/**
 * Paste is always intercepted: the pasted text is spliced into the
 * serialized value and the whole thing re-parsed, so known `$` / `@`
 * tokens and `{...}` placeholders chip-ify instead of landing as plain
 * text (native paste could only ever write into one segment anyway).
 */
function onPaste(seg, e) {
    if (!e.clipboardData) return;
    e.preventDefault();

    const pasted = e.clipboardData.getData('text/plain').replace(/[\r\n]/g, '');

    let fullValue;
    let endOffset; // absolute serialized offset where the pasted text ends

    if (allSelected.value) {
        allSelected.value = false;
        fullValue = pasted;
        endOffset = pasted.length;
    } else {
        const start = e.target.selectionStart ?? seg.value.length;
        const end = e.target.selectionEnd ?? start;
        endOffset = serializedOffsetOf(segments.value, seg.id, start) + pasted.length;
        seg.value = seg.value.slice(0, start) + pasted + seg.value.slice(end);
        fullValue = serialize();
    }

    // Re-parsing rebuilds every segment id — drop any trigger anchored to
    // the old ones before the swap.
    picker.clearTrigger();
    setFromValue(fullValue, tokenKinds.value);
    emit('update:modelValue', serialize());

    const landing = segmentAtOffset(segments.value, endOffset);
    if (landing) {
        focusSegment(landing.segId, landing.cursorPos);
    }
}

// ---- mutations ----

function onRemoveToken(tokenId) {
    const landing = removeToken(tokenId);
    if (landing) {
        focusSegment(landing.segId, landing.cursorPos);
    }
}

function commitSelection(item) {
    if (!item) return;
    const kind = tokenKinds.value[item.name] || 'custom';

    let landing = null;
    const trig = picker.triggerState.value;
    if (trig) {
        // Replace the trigger range (`$que`-style live query) with the chip.
        landing = insertToken(trig.segId, trig.startPos, picker.cursorPos.value, item.name, kind);
    } else {
        // Manual flow — chip lands at the last-focused text segment's cursor.
        let target = segments.value.find(s => s.id === lastFocusedSegId.value && s.type === 'text');
        if (!target) {
            target = segments.value[segments.value.length - 1];
        }
        const pos = Math.max(0, Math.min(picker.cursorPos.value ?? target.value.length, target.value.length));
        landing = insertToken(target.id, pos, pos, item.name, kind);
    }

    picker.clearTrigger();
    picker.closeManual();
    if (landing) {
        focusSegment(landing.segId, landing.cursorPos);
    }
}

// ---- outside clicks ----

// Deliberately NOT useDropdown: that composable dismisses against a single
// root element, while this picker has two zones — manual mode closes on
// clicks outside the picker wrap, trigger mode outside the whole component.
function onDocumentMousedown(e) {
    if (!picker.pickerVisible.value) return;
    const insideComponent = rootEl.value?.contains(e.target);
    // Manual picker: close on click outside the picker wrapper.
    if (picker.manualOpen.value && (!pickerWrap.value || !pickerWrap.value.contains(e.target))) {
        picker.closeManual();
    }
    // Trigger picker: close on click outside the whole component.
    if (picker.triggerState.value && !insideComponent) {
        picker.clearTrigger();
    }
}

onMounted(() => document.addEventListener('mousedown', onDocumentMousedown));
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentMousedown));
</script>

<style>
/* Token input, chips, and token-picker visuals (also used by TokenChip /
   TokenPickerMenu, and reached by SchemaForm / SiteEndpointsTable) - moved
   here from the monolithic links.css. Unscoped/class-namespaced. */
/* ---------------------------------------------------------------------
   TokenizedInput — flex row mimicking a single text input, with native
   <input> elements for text segments interleaved with colored chips and
   a built-in "+ token" dropdown.
--------------------------------------------------------------------- */
.influx-tokenized-input {
    display: flex;
    align-items: stretch;
    width: 100%;
    min-height: 34px;
    background: #fff;
    border: 1px solid hsla(212deg, 25%, 50%, 0.25);
    border-radius: 5px;
}
/* Craft's CP CSS applies a universal `:focus-within { box-shadow: var(--focus-ring) }`
   that lights up every ancestor — and the focused element itself — when
   something inside takes focus. Suppress the bubbled glow on both the
   container and the inner segment inputs; the in-flow cursor and the
   chip colors already make the focused state obvious. */
.influx-tokenized-input:focus-within,
.influx-tokenized-input .influx-tokenized-text:focus,
.influx-tokenized-input .influx-tokenized-text:focus-within,
.influx-tokenized-input .influx-tokenized-text:focus-visible {
    box-shadow: none;
}
.influx-tokenized-input.disabled {
    background: #f7f9fc;
}
/* Inside an editable-table cell (site endpoints) the cell already draws
   the border — flatten the control so it doesn't read as a box-in-a-box. */
.influx-site-endpoints table.editable .influx-tokenized-input {
    border: none;
    border-radius: 0;
    background: transparent;
}

.influx-tokenized-segments {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    flex: 1 1 auto;
    min-width: 0;
    padding: 5px 4px 5px 9px;
    /* Tight inline feel — chips and text segments butt right up against
       each other so the URL reads as one continuous string. */
    gap: 0;
    row-gap: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 13px;
    line-height: 1.5;
}

.influx-tokenized-text {
    border: 0;
    background: transparent;
    outline: none;
    padding: 0;
    margin: 0;
    font: inherit;
    color: inherit;
    /* Grow inputs to fit their content; min-width keeps an empty segment
       between chips clickable. `field-sizing: content` is widely supported
       in mid-2026 evergreen browsers — older shells fall back to the
       default ~150px, which is ugly but functional. */
    field-sizing: content;
    min-width: 2px;
    max-width: 100%;
}
.influx-tokenized-text::placeholder {
    color: #9aa4ad;
    font-family: var(--craft-cp-font, system-ui, sans-serif);
    font-size: 14px;
}
.influx-tokenized-text:disabled {
    cursor: not-allowed;
}

/* Component-level select-all (Cmd/Ctrl+A): native selection can't span the
   separate segment inputs, so paint an equivalent highlight — a selection-
   blue wash over the text segments and a matching ring around the chips
   (they keep their kind colors, so a tint alone would get lost). */
.influx-tokenized-input.all-selected .influx-tokenized-text {
    background: hsla(208deg, 90%, 60%, 0.25);
    border-radius: 2px;
}
.influx-tokenized-input.all-selected .influx-tokenized-chip {
    outline: 2px solid hsla(208deg, 90%, 55%, 0.5);
    outline-offset: 0;
}

/* Inline chip — sized to slot inside the monospaced text flow without
   blowing up the row height. Colors come from data-kind below. */
.influx-tokenized-chip {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    padding: 1px 4px 1px 6px;
    border-radius: 4px;
    border: 1px solid transparent;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    user-select: none;
}
.influx-tokenized-chip .chip-name {
    /* Empty rule reserved for future tweaks — the chip itself does the work. */
}
.influx-tokenized-chip .chip-remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 12px;
    height: 12px;
    border: 0;
    background: transparent;
    color: inherit;
    cursor: pointer;
    padding: 0;
    margin-inline-start: 2px;
    border-radius: 50%;
    opacity: 0.55;
    transition: opacity 0.1s ease, background-color 0.1s ease;
}
.influx-tokenized-chip .chip-remove:hover,
.influx-tokenized-chip .chip-remove:focus-visible {
    opacity: 1;
    background: rgba(0, 0, 0, 0.08);
    outline: none;
}
.influx-tokenized-chip .chip-remove svg {
    display: block;
}

/* Per-kind chip colors — Element=green, Site=blue, Fields/custom=gray. */
.influx-tokenized-chip[data-kind="element"] {
    background: #e4f5e5;
    color: #1f6f30;
    border-color: #b9e0bf;
}
.influx-tokenized-chip[data-kind="site"] {
    background: #e1eefc;
    color: #1c4f8a;
    border-color: #b9d3f0;
}
.influx-tokenized-chip[data-kind="fields"],
.influx-tokenized-chip[data-kind="custom"] {
    background: #ececef;
    color: #4a4f57;
    border-color: #d4d6db;
}
.influx-tokenized-chip[data-kind="env"] {
    background: #fff4d6;
    color: #8a6a00;
    border-color: #f0d676;
}
.influx-tokenized-chip[data-kind="alias"] {
    background: #efe3fb;
    color: #5a26a0;
    border-color: #d4baee;
}

/* "+ Insert token" trigger — small icon button docked to the right edge
   of the input. Stays inside the bordered shell so it reads as one
   integrated field. */
.influx-tokenized-picker-wrap {
    position: relative;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    padding-inline-end: 4px;
}
.influx-tokenized-picker-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 4px;
    background: transparent;
    color: #6b7280;
    cursor: pointer;
    transition: background-color 0.1s ease, color 0.1s ease, border-color 0.1s ease;
}
.influx-tokenized-picker-btn:hover,
.influx-tokenized-picker-btn.active {
    background: hsl(208deg, 100%, 96%);
    color: hsl(208deg, 100%, 38%);
    border-color: hsl(208deg, 100%, 85%);
}
.influx-tokenized-picker-btn svg {
    display: block;
}

/* Dropdown menu */
.influx-tokenized-picker-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 200;
    min-width: 280px;
    max-height: 360px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #d7dfe7;
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba(20, 30, 50, 0.16), 0 2px 6px rgba(20, 30, 50, 0.06);
    padding: 6px 0;
}
.influx-tokenized-picker-menu h6 {
    margin: 8px 14px 4px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #8b95a3;
}
.influx-tokenized-picker-menu h6:first-child {
    margin-top: 4px;
}
.influx-tokenized-picker-menu ul {
    list-style: none;
    margin: 0;
    padding: 0 4px;
}
.influx-tokenized-picker-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 5px 8px;
    border: 0;
    border-radius: 4px;
    background: transparent;
    cursor: pointer;
    text-align: left;
    font-size: 13px;
    transition: background-color 0.08s ease;
}
.influx-tokenized-picker-item:hover,
.influx-tokenized-picker-item:focus-visible,
.influx-tokenized-picker-item.highlighted {
    background: hsl(208deg, 100%, 96%);
    outline: none;
}
.influx-tokenized-picker-item .hint {
    color: #6b7280;
    font-size: 12px;
}

/* Search input at the top of the manually-opened picker — `+`-mode only.
   Triggered-mode (`$` / `@` / `{`) reads its query straight off the URL
   input so a duplicate search box would be confusing. */
.influx-tokenized-picker-search {
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
.influx-tokenized-picker-search input {
    flex: 1 1 auto;
    border: 0;
    background: transparent;
    outline: none;
    padding: 0;
    font: inherit;
    color: #1f2937;
}
.influx-tokenized-picker-search input::placeholder {
    color: #9aa4ad;
}
.influx-tokenized-picker-search input:focus {
    box-shadow: none;
}

.influx-tokenized-picker-empty {
    margin: 4px 14px 10px;
    color: #6b7280;
    font-size: 12px;
}
.influx-tokenized-picker-empty code {
    background: #ececef;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 11px;
}


/* Mini inline chip used inside the picker dropdown — reuses the chip
   color scheme so users see the same visual hint they'll get in-input. */
.influx-tokenized-chip-inline {
    display: inline-flex;
    align-items: center;
    padding: 1px 6px;
    border: 1px solid transparent;
    border-radius: 4px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.4;
}
.influx-tokenized-chip-inline[data-kind="element"] {
    background: #e4f5e5;
    color: #1f6f30;
    border-color: #b9e0bf;
}
.influx-tokenized-chip-inline[data-kind="site"] {
    background: #e1eefc;
    color: #1c4f8a;
    border-color: #b9d3f0;
}
.influx-tokenized-chip-inline[data-kind="fields"],
.influx-tokenized-chip-inline[data-kind="custom"],
.influx-tokenized-chip-inline[data-kind="node"] {
    background: #ececef;
    color: #4a4f57;
    border-color: #d4d6db;
}
.influx-tokenized-chip-inline[data-kind="env"] {
    /* Amber — matches Craft's "environment-variable" accent on
       env-suggesting fields elsewhere in the CP. */
    background: #fff4d6;
    color: #8a6a00;
    border-color: #f0d676;
}
.influx-tokenized-chip-inline[data-kind="alias"] {
    /* Violet — distinct from any other kind in the picker so users can
       tell aliases apart from env vars at a glance. */
    background: #efe3fb;
    color: #5a26a0;
    border-color: #d4baee;
}


/* Token pills inside the picker menu — one accent per group so the user can
   tell at a glance whether a token is native, site-scoped, or comes from a
   custom field. The colors match the chip styles used elsewhere in the CP. */
.influx-token-pill {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 9px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid transparent;
}
.influx-token-group[data-kind="element"] .influx-token-pill {
    background: #e4f5e5;
    color: #1f6f30;
    border-color: #b9e0bf;
}
.influx-token-group[data-kind="site"] .influx-token-pill {
    background: #e1eefc;
    color: #1c4f8a;
    border-color: #b9d3f0;
}
.influx-token-group[data-kind="fields"] .influx-token-pill,
.influx-token-group[data-kind="custom"] .influx-token-pill {
    background: #ececef;
    color: #4a4f57;
    border-color: #d4d6db;
}
</style>
