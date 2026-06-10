import { describe, expect, it, vi } from 'vitest';
import { parseSegments, serializeSegments, useTokenSegments } from '../useTokenSegments.js';

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
