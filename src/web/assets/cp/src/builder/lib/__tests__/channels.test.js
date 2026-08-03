import { describe, expect, it } from 'vitest';
import { channelFor, flattenChannels, splitChannels } from '../channels.js';

/**
 * The split and join behind a sub-field card that spans both mapping channels.
 * Pure, so the rules live here once instead of being re-asserted through two
 * components: which channel a row belongs in, and that partitioning a table
 * back apart loses nothing.
 */

const SUB_FIELDS = [
    { handle: 'alt', label: 'Alt text' },
    { handle: 'caption', label: 'Caption', channel: 'fields' },
];

describe('flattenChannels', () => {
    it('reads both channels as one table', () => {
        expect(flattenChannels({
            fields: { caption: { node: 'c' } },
            nativeFields: { alt: { node: 'a' } },
        })).toEqual({ caption: { node: 'c' }, alt: { node: 'a' } });
    });

    it('lets nativeFields win a collision — the native is the row that applies', () => {
        expect(flattenChannels({
            fields: { alt: { node: 'custom' } },
            nativeFields: { alt: { node: 'native' } },
        })).toEqual({ alt: { node: 'native' } });
    });

    it('handles a half-saved or absent entry', () => {
        expect(flattenChannels({ nativeFields: { alt: {} } })).toEqual({ alt: {} });
        expect(flattenChannels(null)).toEqual({});
    });
});

describe('channelFor', () => {
    it('takes what the schema node declares', () => {
        expect(channelFor('caption', SUB_FIELDS, {}, 'nativeFields')).toBe('fields');
    });

    it('falls back to the caller\'s default for a declared row with no channel', () => {
        // The same node list, read by the two card types: each defaults to the
        // channel its own rows were stored in before the key existed.
        expect(channelFor('alt', SUB_FIELDS, {}, 'nativeFields')).toBe('nativeFields');
        expect(channelFor('alt', SUB_FIELDS, {}, 'fields')).toBe('fields');
    });

    it('sends an undeclared handle back to the channel it was saved in', () => {
        const saved = { fields: { gone: { node: 'x' } }, nativeFields: { stale: { node: 'y' } } };

        expect(channelFor('stale', SUB_FIELDS, saved, 'fields')).toBe('nativeFields');
        expect(channelFor('gone', SUB_FIELDS, saved, 'nativeFields')).toBe('fields');
    });

    it('defaults an undeclared, unsaved handle', () => {
        expect(channelFor('mystery', SUB_FIELDS, {}, 'nativeFields')).toBe('nativeFields');
    });
});

describe('splitChannels', () => {
    it('partitions the table and always returns both channels', () => {
        expect(splitChannels(
            { alt: { node: 'a' }, caption: { node: 'c' } },
            SUB_FIELDS,
            {},
            'nativeFields',
        )).toEqual({ fields: { caption: { node: 'c' } }, nativeFields: { alt: { node: 'a' } } });

        // An untouched channel comes back empty rather than absent, so a caller
        // can write it without checking.
        expect(splitChannels({}, SUB_FIELDS, {}, 'nativeFields')).toEqual({ fields: {}, nativeFields: {} });
    });

    it('preserves row order within a channel', () => {
        const subFields = [
            { handle: 'one', channel: 'fields' },
            { handle: 'two', channel: 'fields' },
        ];
        const rows = { two: { node: '2' }, one: { node: '1' } };

        expect(Object.keys(splitChannels(rows, subFields, {}, 'fields').fields)).toEqual(['two', 'one']);
    });

    it('never mutates its inputs', () => {
        const rows = { alt: { node: 'a' } };
        const saved = { fields: {}, nativeFields: {} };
        splitChannels(rows, SUB_FIELDS, saved, 'nativeFields');

        expect(rows).toEqual({ alt: { node: 'a' } });
        expect(saved).toEqual({ fields: {}, nativeFields: {} });
    });
});
