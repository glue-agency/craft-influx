import { describe, expect, it } from 'vitest';
import {
    detectBlockSource,
    feedKeysIn,
    leafPaths,
    listAt,
    relativeNodesFor,
    typeKeys,
    typeOfElement,
} from '../relativeNodes.js';

/**
 * The two PHP twins are what these specs are really about: `leafPaths` against
 * `FeedInspector::flattenLeafPaths()`, and `typeOfElement` against
 * `Matrix::assignType()`. A suggestion this module makes that the sync reads
 * differently is worse than no suggestion — it maps, saves and silently
 * resolves nothing — so the cases below are the ones where an approximation
 * would diverge.
 */

const values = (options) => options.map((option) => option.value);

describe('leafPaths', () => {
    it('names a scalar leaf and not the object holding it', () => {
        expect(leafPaths({ title: 'Hi', meta: { id: 4, slug: 'hi' } }))
            .toEqual(['title', 'meta.id', 'meta.slug']);
    });

    it('names a list of scalars as one node — a relation takes the whole list', () => {
        expect(leafPaths({ tags: ['a', 'b'] })).toEqual(['tags']);
    });

    it('names a list of objects AND fans its leaves out under the same key', () => {
        // The index collapses away, which is the read RemoteItem::get() performs.
        expect(leafPaths({ directors: [{ name: 'A', role: { key: 'r' } }] }))
            .toEqual(['directors', 'directors.name', 'directors.role.key']);
    });

    it('names an empty child, and drops an empty element outright', () => {
        // Both halves mirror flattenLeafPaths(): only a NON-empty object
        // contributes its leaves instead of its own path, so an empty one is
        // named — it may well hold a value on the next item. An element that is
        // itself empty has no paths at all.
        expect(leafPaths({ empty: {}, filled: 1 })).toEqual(['empty', 'filled']);
        expect(leafPaths({})).toEqual([]);
    });
});

describe('listAt', () => {
    const item = { blocks: [{ a: 1 }], meta: { rows: [{ b: 2 }] }, title: 'x' };

    it('resolves a dot path to its list', () => {
        expect(listAt(item, 'blocks')).toEqual([{ a: 1 }]);
        expect(listAt(item, 'meta.rows')).toEqual([{ b: 2 }]);
    });

    it('answers null for everything that isn’t a resolvable list', () => {
        expect(listAt(item, 'title')).toBeNull();
        expect(listAt(item, 'nope.deeper')).toBeNull();
        expect(listAt(item, '')).toBeNull();
        expect(listAt(null, 'blocks')).toBeNull();
    });
});

describe('typeKeys', () => {
    it('keys each type by its alias, falling back to its own handle', () => {
        expect(typeKeys(['text', 'quote'], { sourceKey_quote: 'blockquote' }))
            .toEqual({ text: 'text', blockquote: 'quote' });
    });
});

describe('typeOfElement', () => {
    const keys = { text: 'text', quote: 'quote' };

    it('reads a keyed element’s own key, and hands back the payload under it', () => {
        const element = { id: 4182, text: { body: 'Hello' } };

        expect(typeOfElement(element, 'listByKey', keys))
            .toEqual({ handle: 'text', payload: { body: 'Hello' } });
    });

    it('reads a noded element’s discriminator, and keeps the element as payload', () => {
        const element = { type: 'quote', cite: 'A' };

        expect(typeOfElement(element, 'listByNode', keys, { typeNode: 'type' }))
            .toEqual({ handle: 'quote', payload: element });
    });

    it('defaults the discriminator to `type`, as the strategy does', () => {
        expect(typeOfElement({ type: 'text' }, 'listByNode', keys)?.handle).toBe('text');
    });

    it('attributes every element to the one type under a single-type list', () => {
        expect(typeOfElement({ body: 'x' }, 'listSingle', keys, {}, ['quote']))
            .toEqual({ handle: 'quote', payload: { body: 'x' } });
    });

    it('claims nothing when no configured type names the element', () => {
        expect(typeOfElement({ id: 1, unknown: {} }, 'listByKey', keys)).toBeNull();
        expect(typeOfElement({ type: 'nope' }, 'listByNode', keys)).toBeNull();
    });
});

describe('relativeNodesFor', () => {
    it('unions leaves across EVERY element of the type, not just the first', () => {
        // The divergence from FeedInspector that matters: element 0 of a block
        // list is one type, and one block of a type need not carry every
        // sub-field. A first-element walk would offer `body` alone.
        const list = [
            { text: { body: 'a' } },
            { quote: { cite: 'c' } },
            { text: { body: 'b', caption: 'cap' } },
        ];

        expect(values(relativeNodesFor(list, 'text', 'listByKey', {}, ['text', 'quote'])))
            .toEqual(['body', 'caption']);
    });

    it('gives each card only its own type’s nodes', () => {
        const list = [{ text: { body: 'a' } }, { quote: { cite: 'c' } }];

        expect(values(relativeNodesFor(list, 'quote', 'listByKey', {}, ['text', 'quote'])))
            .toEqual(['cite']);
    });

    it('resolves paths relative to the ELEMENT under a noded list', () => {
        const list = [{ type: 'quote', cite: 'A', meta: { source: 'S' } }];

        expect(values(relativeNodesFor(list, 'quote', 'listByNode', {}, ['quote'])))
            .toEqual(['type', 'cite', 'meta.source']);
    });

    it('honours a feed alias, so a differently-spelled type still resolves', () => {
        const list = [{ blockquote: { cite: 'A' } }];
        const options = { sourceKey_quote: 'blockquote' };

        expect(values(relativeNodesFor(list, 'quote', 'listByKey', options, ['quote'])))
            .toEqual(['cite']);
    });

    it('offers nothing when the list doesn’t resolve', () => {
        expect(relativeNodesFor(null, 'text', 'listByKey', {}, ['text'])).toEqual([]);
    });
});

describe('feedKeysIn', () => {
    it('offers a keyed list’s container keys, and never its scalar siblings', () => {
        // `id` is metadata beside the payload — assignType() skips it for the
        // same reason, so offering it would offer a key the sync won't accept.
        const list = [
            { id: 4182, text: { body: 'a' } },
            { id: 4183, quote: { cite: 'c' } },
            { id: 4184, text: { body: 'b' } },
        ];

        expect(values(feedKeysIn(list, 'listByKey'))).toEqual(['text', 'quote']);
    });

    it('offers a noded list’s discriminator values, from the configured node', () => {
        const list = [{ kind: 'text' }, { kind: 'quote' }, { kind: 'text' }];

        expect(values(feedKeysIn(list, 'listByNode', { typeNode: 'kind' }))).toEqual(['text', 'quote']);
        // …and defaults the node the way the strategy does.
        expect(values(feedKeysIn([{ type: 'text' }], 'listByNode'))).toEqual(['text']);
    });

    it('offers nothing for a source that claims nothing by name', () => {
        expect(feedKeysIn([{ body: 'a' }], 'listSingle')).toEqual([]);
        expect(feedKeysIn(null, 'listByKey')).toEqual([]);
    });
});

describe('detectBlockSource', () => {
    const handles = ['text', 'quote'];

    it('reads a keyed list', () => {
        const list = [{ text: { body: 'a' } }, { quote: { cite: 'c' } }];

        expect(detectBlockSource(list, handles)).toEqual({ source: 'listByKey' });
    });

    it('reads a noded list, and names the node that carries the type', () => {
        const list = [{ kind: 'text', body: 'a' }, { kind: 'quote', cite: 'c' }];

        expect(detectBlockSource(list, handles)).toEqual({ source: 'listByNode', typeNode: 'kind' });
    });

    it('prefers keyed over noded when an element is both', () => {
        // The keyed shape is the narrower claim, and a keyed element carrying a
        // `type` node beside its payload is a real feed shape.
        const list = [{ type: 'text', text: { body: 'a' } }];

        expect(detectBlockSource(list, handles)).toEqual({ source: 'listByKey' });
    });

    it('reads a list naming no type at all as single-type', () => {
        const list = [{ body: 'a' }, { body: 'b' }];

        expect(detectBlockSource(list, handles)).toEqual({ source: 'listSingle' });
    });

    it('refuses a node that only sometimes names a type', () => {
        // A content field that overlaps a handle on one element is not the
        // discriminator; without one, the list reads as single-type.
        const list = [{ label: 'text', body: 'a' }, { label: 'whatever', body: 'b' }];

        expect(detectBlockSource(list, handles)).toEqual({ source: 'listSingle' });
    });

    it('answers null when there is nothing to read', () => {
        expect(detectBlockSource(null, handles)).toBeNull();
        expect(detectBlockSource([], handles)).toBeNull();
        expect(detectBlockSource([{ body: 'a' }], [])).toBeNull();
        expect(detectBlockSource(['scalar'], handles)).toBeNull();
    });
});
