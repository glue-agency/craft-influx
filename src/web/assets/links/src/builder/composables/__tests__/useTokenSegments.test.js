import { describe, expect, it, vi } from 'vitest';
import {
    parseSegments, segmentAtOffset, serializedOffsetOf, serializeSegments, useTokenSegments,
} from '../useTokenSegments.js';

const KINDS = { '$API_BASE': 'env', '@data': 'alias', '{id}': 'element' };

const roundTrip = (value) => serializeSegments(parseSegments(value, KINDS));

describe('parseSegments / serializeSegments', () => {
    it('round-trips every chip-bearing shape losslessly', () => {
        for (const value of [
            '',
            'https://example.test/api',
            '$API_BASE/articles?after={id}',
            '@data/feed.json',
            '{id}{id}',
            'plain $API_BASE middle @data end',
        ]) {
            expect(roundTrip(value)).toBe(value);
        }
    });

    it('always chips {curly} tokens, known or not', () => {
        const segments = parseSegments('/items/{unknownToken}', KINDS);
        const chips = segments.filter(s => s.type === 'token');
        expect(chips.map(c => c.name)).toEqual(['{unknownToken}']);
        expect(chips[0].kind).toBe('custom');
    });

    it('only chips $ENV / @alias forms from the known set', () => {
        const segments = parseSegments('cost $5 at email@example.com via $API_BASE', KINDS);
        const chips = segments.filter(s => s.type === 'token');
        expect(chips.map(c => c.name)).toEqual(['$API_BASE']);
        // The unknown fragments stay plain text — and survive a round-trip.
        expect(serializeSegments(segments)).toBe('cost $5 at email@example.com via $API_BASE');
    });

    it('always yields text segments around chips so the cursor can land anywhere', () => {
        const segments = parseSegments('{id}', KINDS);
        expect(segments.map(s => s.type)).toEqual(['text', 'token', 'text']);
        expect(segments[0].value).toBe('');
        expect(segments[2].value).toBe('');
    });
});

describe('serializedOffsetOf / segmentAtOffset', () => {
    // 'https://' (8) + '$API_BASE' (9 as a chip) + '/items' (6)
    const segs = parseSegments('https://$API_BASE/items', KINDS);
    const [head, , tail] = segs;

    it('maps a mid-segment cursor to its absolute serialized offset and back', () => {
        expect(serializedOffsetOf(segs, head.id, 4)).toBe(4);
        expect(segmentAtOffset(segs, 4)).toEqual({ segId: head.id, cursorPos: 4 });

        expect(serializedOffsetOf(segs, tail.id, 2)).toBe('https://$API_BASE'.length + 2);
        expect(segmentAtOffset(segs, 19)).toEqual({ segId: tail.id, cursorPos: 2 });
    });

    it('keeps boundary offsets on the text segment that owns them', () => {
        // End of the head text — stays on the head, not inside the chip.
        expect(segmentAtOffset(segs, 8)).toEqual({ segId: head.id, cursorPos: 8 });
        // Exactly after the chip — start of the tail.
        expect(serializedOffsetOf(segs, tail.id, 0)).toBe(17);
        expect(segmentAtOffset(segs, 17)).toEqual({ segId: tail.id, cursorPos: 0 });
    });

    it('lands offsets inside a chip right after it', () => {
        expect(segmentAtOffset(segs, 12)).toEqual({ segId: tail.id, cursorPos: 0 });
    });

    it('clamps past-the-end offsets, cursor positions, and unknown ids', () => {
        expect(segmentAtOffset(segs, 999)).toEqual({ segId: tail.id, cursorPos: tail.value.length });
        expect(serializedOffsetOf(segs, head.id, 999)).toBe(8);
        expect(serializedOffsetOf(segs, -1, 3)).toBe('https://$API_BASE/items'.length);
    });
});

describe('useTokenSegments mutations', () => {
    const build = (value) => {
        const onChange = vi.fn();
        const api = useTokenSegments({ onChange });
        api.setFromValue(value, KINDS);
        return { ...api, onChange };
    };

    it('removeToken joins the neighboring text segments and reports the cursor seam', () => {
        const { segments, removeToken, serialize, onChange } = build('https://$API_BASE/items');
        const chip = segments.value.find(s => s.type === 'token');

        const landing = removeToken(chip.id);

        expect(serialize()).toBe('https:///items');
        expect(onChange).toHaveBeenLastCalledWith('https:///items');
        // Cursor lands at the join point, on the surviving text segment.
        expect(landing.cursorPos).toBe('https://'.length);
        expect(segments.value.find(s => s.id === landing.segId).value).toBe('https:///items');
    });

    it('insertToken replaces a trigger range (the typed live query)', () => {
        const { segments, insertToken, serialize } = build('https://$API/items');
        const seg = segments.value[0];

        // The user typed `$API` starting at index 8; selecting replaces it.
        const landing = insertToken(seg.id, 8, 12, '$API_BASE', 'env');

        expect(serialize()).toBe('https://$API_BASE/items');
        expect(segments.value.find(s => s.id === landing.segId).value).toBe('/items');
        expect(landing.cursorPos).toBe(0);
    });

    it('insertToken at a zero-width range inserts at the cursor (manual flow)', () => {
        const { segments, insertToken, serialize } = build('ab');
        const seg = segments.value[0];

        insertToken(seg.id, 1, 1, '{id}', 'element');

        expect(serialize()).toBe('a{id}b');
    });

    it('insertToken refuses non-text targets', () => {
        const { segments, insertToken } = build('x{id}y');
        const chip = segments.value.find(s => s.type === 'token');
        expect(insertToken(chip.id, 0, 0, '{slug}', 'element')).toBe(null);
    });

    it('recolor re-tints chips when the known set changes', () => {
        const { segments, recolor } = build('$API_BASE');
        recolor({});
        expect(segments.value.find(s => s.type === 'token').kind).toBe('custom');
    });
});
