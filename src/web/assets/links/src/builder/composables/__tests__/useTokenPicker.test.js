import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { useTokenPicker } from '../useTokenPicker.js';

const GROUPS = [
    { kind: 'element', label: 'Element', data: [
        { name: '{id}', hint: 'Element ID' },
        { name: '{slug}', hint: 'Slug' },
    ] },
    { kind: 'env', label: 'Environment', data: [
        { name: '$API_BASE', hint: 'Base URL' },
    ] },
];

const build = (segmentText = '') => {
    let text = segmentText;
    const picker = useTokenPicker({
        groups: () => GROUPS,
        segmentValue: () => text,
    });
    return { picker, setText: (v) => { text = v; } };
};

describe('trigger state machine', () => {
    it('opens on a freshly-typed trigger char at the start or after a separator', () => {
        const { picker } = build();
        picker.maybeOpenTrigger(1, '', '$', 1);
        expect(picker.triggerState.value).toEqual({ segId: 1, startPos: 0 });

        picker.clearTrigger();
        picker.maybeOpenTrigger(1, 'a/', 'a/{', 3);
        expect(picker.triggerState.value).toEqual({ segId: 1, startPos: 2 });
    });

    it('stays closed mid-word and on multi-char edits (paste)', () => {
        const { picker } = build();
        // `abc{` — trigger typed directly after a word character.
        picker.maybeOpenTrigger(1, 'abc', 'abc{', 4);
        expect(picker.triggerState.value).toBe(null);

        // Paste inserted more than the single trigger char.
        picker.maybeOpenTrigger(1, 'a', 'a x$', 4);
        expect(picker.triggerState.value).toBe(null);
    });

    it('closes when a terminator lands in the query range or the trigger is deleted', () => {
        const { picker, setText } = build();
        picker.maybeOpenTrigger(1, '', '$', 1);

        setText('$ap/');
        picker.maybeCloseTrigger(1, '$ap/', 4);
        expect(picker.triggerState.value).toBe(null);

        picker.maybeOpenTrigger(1, '', '$', 1);
        picker.maybeCloseTrigger(1, '', 0); // trigger char deleted
        expect(picker.triggerState.value).toBe(null);
    });

    it('closes when the cursor moves before the trigger anchor', () => {
        const { picker, setText } = build();
        setText('ab$');
        picker.maybeOpenTrigger(1, 'ab', 'ab$', 3);
        expect(picker.triggerState.value).toBe(null); // 'b' before $ is mid-word

        setText('a/$');
        picker.maybeOpenTrigger(1, 'a/', 'a/$', 3);
        expect(picker.triggerState.value).not.toBe(null);
        picker.trackCursor(1, 1);
        expect(picker.triggerState.value).toBe(null);
    });
});

describe('query + filtering', () => {
    it('slices the live query from the trigger anchor to the cursor', () => {
        const { picker, setText } = build();
        setText('/$sl');
        picker.maybeOpenTrigger(1, '/', '/$', 2);
        picker.setCursor(4);

        expect(picker.effectiveQuery.value).toBe('$sl');
    });

    it('filters by name or hint, keeping flat indexes addressable', () => {
        const { picker } = build();
        picker.openManual();
        picker.searchQuery.value = 'slug';

        expect(picker.filteredGroups.value).toHaveLength(1);
        expect(picker.flatItems.value.map(i => i.name)).toEqual(['{slug}']);
        expect(picker.flatItems.value[0]._flatIdx).toBe(0);
    });

    it('wraps the highlight around both ends', () => {
        const { picker } = build();
        picker.openManual();

        picker.moveHighlight(-1);
        expect(picker.highlightedIndex.value).toBe(2);
        picker.moveHighlight(1);
        expect(picker.highlightedIndex.value).toBe(0);
        expect(picker.highlightedItem.value.name).toBe('{id}');
    });

    it('clamps the highlight when the filtered list shrinks', async () => {
        const { picker } = build();
        picker.openManual();
        picker.moveHighlight(-1); // index 2

        picker.searchQuery.value = 'slug'; // one item left
        await nextTick();
        expect(picker.highlightedIndex.value).toBe(0);
    });
});
