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
