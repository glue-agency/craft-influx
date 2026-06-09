<template>
    <div
        class="influx-tokenized-input"
        :class="{ disabled }"
        @click="onContainerClick"
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
                    @keyup="trackCursor(seg, $event)"
                    @mouseup="trackCursor(seg, $event)"
                    @focus="onTextFocus(seg, $event)"
                    @blur="onTextBlur"
                />
                <span
                    v-else
                    class="influx-tokenized-chip"
                    :data-kind="seg.kind || 'custom'"
                >
                    <span class="chip-name">{{ seg.name }}</span>
                    <button
                        v-if="!disabled"
                        type="button"
                        class="chip-remove"
                        tabindex="-1"
                        :aria-label="$t('Remove {name}', { name: seg.name })"
                        @mousedown.prevent
                        @click.stop="removeTokenById(seg.id)"
                    >
                        <svg width="8" height="8" viewBox="0 0 8 8" aria-hidden="true">
                            <path d="M1 1l6 6M7 1l-6 6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        </svg>
                    </button>
                </span>
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
                :class="{ active: pickerVisible }"
                :aria-expanded="pickerVisible ? 'true' : 'false'"
                aria-haspopup="menu"
                :title="pickerLabel"
                @mousedown.prevent
                @click="toggleManualPicker"
            >
                <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                    <path d="M7 2v10M2 7h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                </svg>
            </button>

            <div v-if="pickerVisible" class="influx-tokenized-picker-menu" role="menu">
                <!-- Search input only shows for manually-opened pickers; when
                     triggered by a keystroke in the URL itself, that input
                     IS the search and a second one would be confusing. -->
                <div v-if="!triggerState" class="influx-tokenized-picker-search">
                    <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                        <circle cx="5" cy="5" r="3.25" stroke="currentColor" stroke-width="1.2" fill="none"/>
                        <path d="M7.5 7.5l3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <input
                        ref="searchInput"
                        type="text"
                        v-model="searchQuery"
                        :placeholder="$t('Filter tokens…')"
                        spellcheck="false"
                        autocomplete="off"
                        @keydown="onSearchKeydown"
                    />
                </div>

                <p v-if="!filteredGroups.length" class="influx-tokenized-picker-empty">
                    {{ $t('No matches for') }} <code>{{ effectiveQuery }}</code>.
                </p>

                <template v-for="group in filteredGroups" :key="group._gid">
                    <h6 v-if="group.label">{{ group.label }}</h6>
                    <ul class="influx-token-group" :data-kind="group.kind || 'custom'">
                        <li v-for="item in group.data" :key="item.name">
                            <button
                                type="button"
                                class="influx-tokenized-picker-item"
                                :class="{ highlighted: highlightedIndex === item._flatIdx }"
                                role="menuitem"
                                @mousemove="highlightedIndex = item._flatIdx"
                                @mousedown.prevent
                                @click="commitSelection(item)"
                            >
                                <span class="influx-tokenized-chip-inline" :data-kind="group.kind || 'custom'">{{ item.name }}</span>
                                <span v-if="item.hint" class="hint">{{ item.hint }}</span>
                            </button>
                        </li>
                    </ul>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * Tokenized text input with inline chips + IDE-style trigger picker.
 *
 * Picker operating modes:
 *   - Triggered: user types `$`, `@`, or `{` while in a text segment. The
 *     picker opens and treats the text from the trigger char through the
 *     current cursor as a live search query — exactly how IDE autocomplete
 *     works. Selecting an item REPLACES the trigger range, not inserts at
 *     cursor.
 *   - Manual: user clicks the `+` button. A search input at the top of the
 *     dropdown takes focus; typing there filters items.
 *
 * Keyboard nav (active whenever the picker is visible):
 *   - ArrowDown / ArrowUp: move the highlight across the flat filtered list.
 *   - Enter:               commit the highlighted item.
 *   - Escape:              close the picker (clears trigger state too).
 *
 * Boundary edits (always on):
 *   - Backspace at position 0 of a text segment removes the preceding chip.
 *   - Delete at end-of-text removes the following chip.
 */

// Patterns chipped during parse:
//   - `{name}` — always chipped; SPA-owned placeholders.
//   - `$NAME`  — chipped only if NAME is in the known suggestion set.
//   - `@name`  — chipped only if @name is in the known suggestion set.
// The known-set guard keeps `email@example.com` and `cost $5` from
// accidentally turning their fragments into chips.
const TOKEN_RE_SOURCE = '\\{[^{}\\s]+\\}|\\$[A-Za-z_][A-Za-z0-9_]*|@[A-Za-z][A-Za-z0-9_\\-]*';
const TRIGGER_CHARS = ['$', '@', '{'];
// Characters that, when typed after a trigger, abort the trigger. Keeps
// `$API_BASE` triggered through underscores / digits / dots but cancels
// when the user moves on to a separator like `/` or whitespace.
const TRIGGER_TERMINATOR_RE = /[\s/?#&=\\]/;

let segIdCounter = 0;
const newSegId = () => ++segIdCounter;

export default {
    name: 'TokenizedInput',

    props: {
        modelValue:  { type: String, default: '' },
        tokenGroups: { type: Array, default: () => [] },
        placeholder: { type: String, default: '' },
        pickerLabel: { type: String, default: 'Insert token' },
        disabled:    { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            segments: [],
            textRefs: {},
            lastFocusedSegId: null,
            lastSelectionStart: 0,

            // Manual picker (opened via the `+` button)
            manualPickerOpen: false,
            searchQuery: '',

            // Trigger picker (opened by typing `$` / `@` / `{` in input)
            // `startPos` is the index of the trigger CHARACTER in the
            // segment, so the query is segment.value.slice(startPos, cursor).
            triggerState: null,

            // Index into the flat filtered list, set by mouse hover or
            // arrow keys. Drives the .highlighted class on menu items.
            highlightedIndex: 0,
        };
    },

    computed: {
        // Map of `name → kind` covering every known suggestion. Used by
        // parse() to decide whether a `$X` / `@y` match should chip, and to
        // color any chip when its segment is built.
        tokenKinds() {
            const out = {};
            for (const group of this.tokenGroups) {
                for (const item of (group.data || [])) {
                    out[item.name] = group.kind || 'custom';
                }
            }
            return out;
        },

        firstTextSegment() {
            return this.segments.find(s => s.type === 'text') || null;
        },

        isEmpty() {
            return this.segments.length === 1
                && this.segments[0].type === 'text'
                && this.segments[0].value === '';
        },

        hasGroups() {
            return (this.tokenGroups || []).some(g => (g.data || []).length > 0);
        },

        pickerVisible() {
            return this.manualPickerOpen || this.triggerState !== null;
        },

        // The string the picker filters on. Triggered mode reads it live
        // off the segment value so the dropdown narrows as the user types.
        effectiveQuery() {
            if (this.triggerState) {
                const seg = this.segments.find(s => s.id === this.triggerState.segId);
                if (!seg) return '';
                return seg.value.slice(this.triggerState.startPos, this.lastSelectionStart);
            }
            return this.searchQuery;
        },

        // Filter groups by query (case-insensitive substring match on name
        // or hint). Each surviving group keeps a `_gid` for `:key` stability
        // and each surviving item gets a `_flatIdx` so the keyboard nav can
        // address items by a single integer.
        filteredGroups() {
            const q = (this.effectiveQuery || '').toLowerCase();
            const out = [];
            let flat = 0;
            for (let gi = 0; gi < this.tokenGroups.length; gi++) {
                const group = this.tokenGroups[gi];
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
        },

        flatItems() {
            const out = [];
            for (const g of this.filteredGroups) out.push(...g.data);
            return out;
        },
    },

    watch: {
        modelValue: {
            immediate: true,
            handler(next) {
                if (this.serialize() === (next || '')) return;
                this.segments = this.parse(next || '', this.tokenKinds);
            },
        },

        tokenGroups: {
            deep: true,
            handler() {
                const kinds = this.tokenKinds;
                for (const seg of this.segments) {
                    if (seg.type === 'token') {
                        seg.kind = kinds[seg.name] || 'custom';
                    }
                }
            },
        },

        // Reset highlight when the filtered list changes shape so the
        // keyboard cursor doesn't land off the end.
        filteredGroups() {
            if (this.highlightedIndex >= this.flatItems.length) {
                this.highlightedIndex = 0;
            }
        },

        // When the manual picker opens, autofocus the search input next tick.
        manualPickerOpen(open) {
            if (open) {
                this.$nextTick(() => this.$refs.searchInput?.focus());
            }
        },
    },

    mounted() {
        document.addEventListener('mousedown', this.onDocumentMousedown);
    },

    beforeUnmount() {
        document.removeEventListener('mousedown', this.onDocumentMousedown);
    },

    methods: {
        // ---- parse / serialize ----

        parse(value, kinds) {
            const segments = [];
            let cursor = 0;
            const re = new RegExp(TOKEN_RE_SOURCE, 'g');
            let m;
            while ((m = re.exec(value)) !== null) {
                const matched = m[0];
                const isCurlyToken = matched[0] === '{';
                // `$ENV` / `@alias` only chip when the literal form is in
                // the known suggestion set — keeps user-typed garbage from
                // looking like a chip and keeps `email@example.com` text.
                if (!isCurlyToken && !(matched in kinds)) {
                    continue;
                }
                segments.push({
                    id: newSegId(),
                    type: 'text',
                    value: value.slice(cursor, m.index),
                });
                segments.push({
                    id: newSegId(),
                    type: 'token',
                    name: matched,
                    kind: kinds[matched] || 'custom',
                });
                cursor = m.index + matched.length;
            }
            segments.push({
                id: newSegId(),
                type: 'text',
                value: value.slice(cursor),
            });
            return segments;
        },

        serialize() {
            return this.segments.map(s => s.type === 'text' ? s.value : s.name).join('');
        },

        emit() {
            this.$emit('update:modelValue', this.serialize());
        },

        // ---- ref / focus tracking ----

        setTextRef(id, el) {
            if (el) {
                this.textRefs[id] = el;
            } else {
                delete this.textRefs[id];
            }
        },

        onTextFocus(seg, e) {
            this.lastFocusedSegId = seg.id;
            this.lastSelectionStart = e.target.selectionStart ?? 0;
            // Switching segments cancels any in-flight trigger — the
            // trigger lives in the segment where it was opened.
            if (this.triggerState && this.triggerState.segId !== seg.id) {
                this.clearTrigger();
            }
        },

        onTextBlur() {
            // No-op for now; the document mousedown handler closes the
            // picker when focus moves outside the wrapper.
        },

        trackCursor(seg, e) {
            this.lastFocusedSegId = seg.id;
            this.lastSelectionStart = e.target.selectionStart ?? 0;
            // Trigger state might need to close if the cursor moved out of
            // its range (user clicked back into the URL before the trigger).
            if (this.triggerState && this.triggerState.segId === seg.id) {
                if (this.lastSelectionStart < this.triggerState.startPos) {
                    this.clearTrigger();
                }
            }
        },

        onContainerClick(e) {
            if (e.target !== this.$el && !e.target.matches('.influx-tokenized-segments')) return;
            const last = this.segments[this.segments.length - 1];
            if (last?.type === 'text') {
                this.focusSegment(last.id, last.value.length);
            }
        },

        focusSegment(id, cursorPos) {
            this.$nextTick(() => {
                const el = this.textRefs[id];
                if (!el) return;
                el.focus();
                const pos = Math.max(0, Math.min(cursorPos ?? 0, el.value.length));
                el.setSelectionRange(pos, pos);
                this.lastFocusedSegId = id;
                this.lastSelectionStart = pos;
            });
        },

        // ---- text-input event handling ----

        onTextInput(seg, e) {
            const previous = seg.value;
            const next = e.target.value;
            const cursor = e.target.selectionStart ?? next.length;

            seg.value = next;
            this.lastSelectionStart = cursor;
            this.lastFocusedSegId = seg.id;

            // Detect a trigger char freshly added at the cursor position.
            // We compare previous vs. next to figure out the inserted span.
            // The simple heuristic: if the previous string is identical to
            // `next` minus the just-typed range, and that range contains a
            // trigger char, open trigger mode anchored at that char.
            if (!this.triggerState) {
                this.maybeOpenTrigger(seg, previous, next, cursor);
            } else if (this.triggerState.segId === seg.id) {
                // Trigger is active — see if the new text invalidates it.
                this.maybeCloseTrigger(seg, cursor);
            }

            this.emit();
        },

        maybeOpenTrigger(seg, prev, next, cursor) {
            if (next.length <= prev.length) return; // user deleted; no trigger
            // Look for a trigger char at cursor-1 (the most recently typed
            // position) and confirm by checking that next === prev with the
            // trigger char inserted there. This avoids opening on paste of
            // a trigger char mid-string when other edits happen too.
            const justTyped = next[cursor - 1];
            if (!TRIGGER_CHARS.includes(justTyped)) return;
            const reconstructed = next.slice(0, cursor - 1) + next.slice(cursor);
            if (reconstructed !== prev) return; // multiple edits; bail

            // Make sure the char immediately before the trigger is a
            // separator (start of segment or non-token character). Avoids
            // accidentally triggering on legitimate `{` inside a URL fragment.
            const before = next[cursor - 2];
            if (before !== undefined && !TRIGGER_TERMINATOR_RE.test(before)) {
                // User is typing mid-word; don't auto-trigger.
                // (e.g. "abc{" inside an already-typed URL fragment)
                return;
            }

            this.triggerState = {
                segId: seg.id,
                startPos: cursor - 1, // index of the trigger char itself
            };
            this.highlightedIndex = 0;
        },

        maybeCloseTrigger(seg, cursor) {
            if (!this.triggerState || this.triggerState.segId !== seg.id) return;
            const startPos = this.triggerState.startPos;
            if (cursor <= startPos) {
                // Cursor moved before the trigger char — likely deleted it.
                this.clearTrigger();
                return;
            }
            // Check the range for any terminator char. If present, close.
            const range = seg.value.slice(startPos, cursor);
            if (range.length === 0) {
                // The trigger char itself was deleted.
                this.clearTrigger();
                return;
            }
            for (let i = 1; i < range.length; i++) {
                if (TRIGGER_TERMINATOR_RE.test(range[i])) {
                    this.clearTrigger();
                    return;
                }
            }
        },

        clearTrigger() {
            this.triggerState = null;
            this.highlightedIndex = 0;
        },

        // ---- key handling on text inputs ----

        onTextKeydown(seg, e) {
            // Picker-driven keys are intercepted only while the picker is
            // open AND a trigger is active OR the picker has focus from
            // the search input. Here we handle the in-input trigger case.
            if (this.pickerVisible && this.triggerState?.segId === seg.id) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    this.moveHighlight(1);
                    return;
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    this.moveHighlight(-1);
                    return;
                }
                if (e.key === 'Enter') {
                    if (this.flatItems[this.highlightedIndex]) {
                        e.preventDefault();
                        this.commitSelection(this.flatItems[this.highlightedIndex]);
                    }
                    return;
                }
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.clearTrigger();
                    return;
                }
            }

            // Boundary chip-eat keys (always on).
            if (e.key === 'Backspace') {
                this.onBackspace(seg, e);
            } else if (e.key === 'Delete') {
                this.onDelete(seg, e);
            }
        },

        onBackspace(seg, e) {
            const input = e.target;
            if (input.selectionStart !== 0 || input.selectionEnd !== 0) return;
            const idx = this.segments.findIndex(s => s.id === seg.id);
            if (idx <= 0) return;
            const prev = this.segments[idx - 1];
            if (prev?.type !== 'token') return;
            e.preventDefault();
            this.removeTokenById(prev.id);
        },

        onDelete(seg, e) {
            const input = e.target;
            if (input.selectionStart !== seg.value.length || input.selectionEnd !== seg.value.length) return;
            const idx = this.segments.findIndex(s => s.id === seg.id);
            const next = this.segments[idx + 1];
            if (next?.type !== 'token') return;
            e.preventDefault();
            this.removeTokenById(next.id);
        },

        // ---- search input keyboard ----

        onSearchKeydown(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.moveHighlight(1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.moveHighlight(-1);
            } else if (e.key === 'Enter') {
                if (this.flatItems[this.highlightedIndex]) {
                    e.preventDefault();
                    this.commitSelection(this.flatItems[this.highlightedIndex]);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.closeManualPicker();
            }
        },

        moveHighlight(delta) {
            const n = this.flatItems.length;
            if (n === 0) return;
            // Wrap around the ends to ease quick scrubbing in long lists.
            this.highlightedIndex = (this.highlightedIndex + delta + n) % n;
        },

        // ---- picker open/close ----

        toggleManualPicker() {
            if (this.manualPickerOpen) {
                this.closeManualPicker();
            } else {
                this.openManualPicker();
            }
        },

        openManualPicker() {
            this.searchQuery = '';
            this.highlightedIndex = 0;
            this.manualPickerOpen = true;
        },

        closeManualPicker() {
            this.manualPickerOpen = false;
            this.searchQuery = '';
        },

        onDocumentMousedown(e) {
            if (!this.pickerVisible) return;
            const wrap = this.$refs.pickerWrap;
            const onTextInput = this.$el.contains(e.target);
            // Manual picker: close on click outside the picker wrapper.
            if (this.manualPickerOpen && (!wrap || !wrap.contains(e.target))) {
                this.closeManualPicker();
            }
            // Trigger picker: close on click outside the whole component.
            if (this.triggerState && !onTextInput) {
                this.clearTrigger();
            }
        },

        // ---- mutations ----

        removeTokenById(tokenId) {
            const idx = this.segments.findIndex(s => s.id === tokenId);
            if (idx < 0 || this.segments[idx].type !== 'token') return;
            const before = this.segments[idx - 1];
            const after = this.segments[idx + 1];
            const joinPoint = before?.value.length ?? 0;
            before.value = (before?.value ?? '') + (after?.value ?? '');
            this.segments.splice(idx, 2);
            this.emit();
            this.focusSegment(before.id, joinPoint);
        },

        commitSelection(item) {
            if (!item) return;
            // The token group an item belongs to; we need its `kind` to
            // color the resulting chip correctly even for chip-typed items
            // selected through the trigger flow where we have no group ref.
            const groupKind = this.kindForItem(item);

            if (this.triggerState) {
                this.replaceTriggerRange(item, groupKind);
            } else {
                this.insertAtCursor(item, groupKind);
            }

            this.clearTrigger();
            this.closeManualPicker();
        },

        kindForItem(item) {
            for (const group of this.tokenGroups) {
                if ((group.data || []).some(d => d.name === item.name)) {
                    return group.kind || 'custom';
                }
            }
            return 'custom';
        },

        replaceTriggerRange(item, groupKind) {
            const trig = this.triggerState;
            const idx = this.segments.findIndex(s => s.id === trig.segId);
            if (idx < 0) return;
            const seg = this.segments[idx];

            // Replace [trig.startPos, cursor] with a chip + trailing text
            // segment. `seg.value` is split: anything before the trigger
            // char stays in this segment, anything after the cursor moves
            // into a fresh trailing text segment.
            const cursor = Math.min(this.lastSelectionStart, seg.value.length);
            const before = seg.value.slice(0, trig.startPos);
            const after = seg.value.slice(cursor);

            seg.value = before;
            const tokenSeg = {
                id: newSegId(),
                type: 'token',
                name: item.name,
                kind: groupKind,
            };
            const afterSeg = { id: newSegId(), type: 'text', value: after };
            this.segments.splice(idx + 1, 0, tokenSeg, afterSeg);
            this.emit();
            this.focusSegment(afterSeg.id, 0);
        },

        insertAtCursor(item, groupKind) {
            // Manual "+ click" flow — chip lands at the last-focused text
            // segment's cursor position.
            let idx = this.segments.findIndex(s => s.id === this.lastFocusedSegId);
            if (idx < 0 || this.segments[idx]?.type !== 'text') {
                idx = this.segments.length - 1;
            }
            const seg = this.segments[idx];
            const cursorPos = Math.max(0, Math.min(this.lastSelectionStart ?? seg.value.length, seg.value.length));

            const before = seg.value.slice(0, cursorPos);
            const after = seg.value.slice(cursorPos);
            seg.value = before;
            const tokenSeg = {
                id: newSegId(),
                type: 'token',
                name: item.name,
                kind: groupKind,
            };
            const afterSeg = { id: newSegId(), type: 'text', value: after };
            this.segments.splice(idx + 1, 0, tokenSeg, afterSeg);
            this.emit();
            this.focusSegment(afterSeg.id, 0);
        },
    },
};
</script>
