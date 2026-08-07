import { describe, it, expect } from 'vitest';
import { autoMatchMappings, clearMappings, discoveredNodes, isMapped, mergeNodeOptions, nodeOption, pruneEmpty, setMappingSlot } from '../mappings.js';

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

describe('clearMappings', () => {
    it('takes each handle whole, sub-field channels included', () => {
        const before = {
            specs: {
                node: 'meta.specs',
                default: 'x',
                useDefault: true,
                options: { format: 'raw' },
                fields: { col1: { node: 'specs.label' } },
                nativeFields: { alt: { node: 'images.0.alt' } },
                blocks: { quote: { fields: { quote: { node: 'quotes.text' } } } },
            },
        };

        expect(clearMappings(before, ['specs'])).toEqual({});
    });

    it('drops every listed handle in one pass and keeps the rest', () => {
        const before = {
            title: { node: 'name' },
            slug: { node: 'meta.slug' },
            author: { default: '12' },
        };

        expect(clearMappings(before, ['title', 'author']))
            .toEqual({ slug: { node: 'meta.slug' } });
    });

    it('ignores handles that carry no mapping', () => {
        expect(clearMappings({ title: { node: 'name' } }, ['slug', 'postDate']))
            .toEqual({ title: { node: 'name' } });
    });

    it('handles an empty handle list and empty mappings', () => {
        expect(clearMappings({ title: { node: 'name' } }, [])).toEqual({ title: { node: 'name' } });
        expect(clearMappings({}, ['title'])).toEqual({});
    });

    it('never mutates the input', () => {
        const before = { title: { node: 'name' }, slug: { node: 'meta.slug' } };
        clearMappings(before, ['title', 'slug']);

        expect(before).toEqual({ title: { node: 'name' }, slug: { node: 'meta.slug' } });
    });
});

describe('nodeOption', () => {
    it('keeps the raw dot syntax so search-by-path matches', () => {
        expect(nodeOption('meta.author.id')).toEqual({ value: 'meta.author.id', label: 'meta.author.id' });
    });
});

describe('mergeNodeOptions', () => {
    it('keeps discovered options first and appends saved-only nodes', () => {
        const discovered = [{ value: 'id', label: 'id' }, { value: 'title', label: 'title' }];
        expect(mergeNodeOptions(discovered, ['meta.slug', 'id']))
            .toEqual([
                { value: 'id', label: 'id' },
                { value: 'title', label: 'title' },
                { value: 'meta.slug', label: 'meta.slug' },
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

describe('discoveredNodes', () => {
    it('reads a partial report as "can\'t know", exactly like no sample at all', () => {
        // A partial report carries an empty flatNodes list; taken literally
        // that would flag every saved mapping as missing.
        expect(discoveredNodes({ flatNodes: [], warning: 'No list of items.' })).toBe(null);
        expect(discoveredNodes(null)).toBe(null);
    });

    it('passes a full report’s nodes through', () => {
        const flatNodes = [{ value: 'id', label: 'id' }];
        expect(discoveredNodes({ flatNodes, warning: null })).toBe(flatNodes);
    });
});

/**
 * Auto-match: the sample's suggestions applied to the fields that have no
 * source node yet. Additive by construction — the specs that matter are the
 * ones about what it REFUSES to touch.
 */
describe('autoMatchMappings', () => {
    const suggestions = [
        { field: 'title', type: 'PlainText', node: 'title' },
        { field: 'summary', type: 'PlainText', node: 'summary' },
    ];
    const nodes = [{ value: 'title', label: 'title' }, { value: 'summary', label: 'summary' }];
    const handles = ['title', 'summary'];

    it('fills the unmapped fields and reports which', () => {
        const { mappings, matched } = autoMatchMappings({}, suggestions, handles, nodes);

        expect(mappings).toEqual({ title: { node: 'title' }, summary: { node: 'summary' } });
        expect(matched).toEqual(['title', 'summary']);
    });

    it('never overwrites a node the user already picked', () => {
        const before = { title: { node: 'meta.headline' } };
        const { mappings, matched } = autoMatchMappings(before, suggestions, handles, nodes);

        expect(mappings.title).toEqual({ node: 'meta.headline' });
        expect(matched).toEqual(['summary']);
    });

    it('leaves a "use default" row alone — that is a decision too', () => {
        const { matched } = autoMatchMappings({ title: { useDefault: true, default: 'x' } }, suggestions, handles, nodes);

        expect(matched).toEqual(['summary']);
    });

    it('keeps the rest of a row it does fill', () => {
        const { mappings } = autoMatchMappings({ title: { default: 'fallback' } }, suggestions, handles, nodes);

        expect(mappings.title).toEqual({ default: 'fallback', node: 'title' });
    });

    it('skips a suggestion whose node the sample no longer carries', () => {
        const { matched } = autoMatchMappings({}, suggestions, handles, [{ value: 'title', label: 'title' }]);

        expect(matched).toEqual(['title']);
    });

    it('skips a suggestion for a field the mapping tree does not offer', () => {
        // Suggestions are computed off the feed, so they name remote keys that
        // may have no destination field at all.
        const { matched } = autoMatchMappings({}, suggestions, ['title'], nodes);

        expect(matched).toEqual(['title']);
    });

    it('never mutates the input, and no-ops on empty inputs', () => {
        const before = { title: { node: 'meta.headline' } };
        autoMatchMappings(before, suggestions, handles, nodes);
        expect(before).toEqual({ title: { node: 'meta.headline' } });

        expect(autoMatchMappings({}, null, null, null)).toEqual({ mappings: {}, matched: [] });
    });
});

describe('isMapped', () => {
    const withSource = { handle: 'body', mapping: { source: [{ type: 'sourceNode' }] } };
    const withoutSource = { handle: 'table', mapping: { source: [] } };

    it('reads a source-cell field off its picked node', () => {
        expect(isMapped(withSource, { node: 'body' })).toBe(true);
        expect(isMapped(withSource, {})).toBe(false);
        expect(isMapped(withSource, null)).toBe(false);
    });

    it('counts anything saved on a field that renders no source cell', () => {
        expect(isMapped(withoutSource, { fields: { a: { node: 'x' } } })).toBe(true);
        expect(isMapped(withoutSource, {})).toBe(false);
    });

    /**
     * A Matrix builds its blocks from a list it names on the row, so block
     * trees alone are an unfinished row rather than a mapped one — which is
     * exactly what the Feed Me converter leaves behind for a shape it can't
     * read.
     */
    it('does not count block trees without the list node they read', () => {
        expect(isMapped(withSource, { blocks: { text: { fields: { body: { node: 'x' } } } } })).toBe(false);
    });
});
