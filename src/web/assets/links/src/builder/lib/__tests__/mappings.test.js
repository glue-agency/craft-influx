import { describe, it, expect } from 'vitest';
import { mergeNodeOptions, nodeOption, pruneEmpty, setMappingSlot } from '../mappings.js';

describe('pruneEmpty', () => {
    it('drops empty strings, null, undefined, false, and empty objects', () => {
        expect(pruneEmpty({
            mode: 'url',
            volume: '',
            folderPath: null,
            upload: false,
            valueMap: {},
            truthy: ['ja'],
        })).toEqual({ mode: 'url', truthy: ['ja'] });
    });

    it('keeps zero and non-empty values', () => {
        expect(pruneEmpty({ limit: 0, match: 'id' })).toEqual({ limit: 0, match: 'id' });
    });

    it('handles null/undefined input', () => {
        expect(pruneEmpty(null)).toEqual({});
        expect(pruneEmpty(undefined)).toEqual({});
    });
});

describe('setMappingSlot', () => {
    it('writes a slot without mutating the input', () => {
        const before = { title: { node: 'name' } };
        const after = setMappingSlot(before, 'title', 'default', 'Untitled');

        expect(after).toEqual({ title: { node: 'name', default: 'Untitled' } });
        expect(before).toEqual({ title: { node: 'name' } });
    });

    it('creates the handle when it did not exist', () => {
        expect(setMappingSlot({}, 'slug', 'node', 'meta.slug'))
            .toEqual({ slug: { node: 'meta.slug' } });
    });

    it('drops an emptied slot', () => {
        expect(setMappingSlot({ title: { node: 'name', default: 'x' } }, 'title', 'default', ''))
            .toEqual({ title: { node: 'name' } });
    });

    it('drops the whole handle when its last slot empties', () => {
        expect(setMappingSlot({ title: { node: 'name' } }, 'title', 'node', ''))
            .toEqual({});
    });

    it('treats empty objects as empty values', () => {
        expect(setMappingSlot({ title: { node: 'name' } }, 'title', 'options', {}))
            .toEqual({ title: { node: 'name' } });
    });
});

describe('nodeOption', () => {
    it('renders dot paths with arrows', () => {
        expect(nodeOption('meta.author.id')).toEqual({ value: 'meta.author.id', label: 'meta → author → id' });
    });
});

describe('mergeNodeOptions', () => {
    it('keeps discovered options first and appends saved-only nodes', () => {
        const discovered = [{ value: 'id', label: 'id' }, { value: 'title', label: 'title' }];
        expect(mergeNodeOptions(discovered, ['meta.slug', 'id']))
            .toEqual([
                { value: 'id', label: 'id' },
                { value: 'title', label: 'title' },
                { value: 'meta.slug', label: 'meta → slug' },
            ]);
    });

    it('accepts plain-string discovered entries and dedupes', () => {
        expect(mergeNodeOptions(['id', 'id', { value: 'name', label: 'name' }], []))
            .toEqual([{ value: 'id', label: 'id' }, { value: 'name', label: 'name' }]);
    });

    it('handles empty inputs', () => {
        expect(mergeNodeOptions(null, null)).toEqual([]);
        expect(mergeNodeOptions(undefined, ['a'])).toEqual([{ value: 'a', label: 'a' }]);
    });
});
