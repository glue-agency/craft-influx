import { describe, expect, it } from 'vitest';
import { channelsFor, readChannels, readNode, setSlot, slotFor, writeChannels, writeNode } from '../slots.js';

/**
 * The whole of what a generic control needs to know that its `type` can't tell it:
 * which slot of the stored mapping it reads and writes. Specced on plain objects —
 * this is the one place in the region renderer with real logic in it, and none of
 * that logic needs a component mounted.
 *
 * The stored shape (models/FieldMapping.php):
 *
 *   { node, default, useDefault, options: {}, fields: {}, nativeFields: {}, blocks: {} }
 *
 * Everything works on ONE mapping rather than the `mappings` map, which is what
 * lets a NESTED sub-field row reuse it: a sub-row's stored shape is itself a whole
 * mapping, so a nested Assets row's `mode` is addressed exactly as a top-level
 * row's is. Dropping an emptied mapping from the map it lives in belongs to
 * whoever owns that map.
 */

const sourceNode = {
    type: 'select',
    allowCustom: true,
    sentinel: { __default__: 'useDefault' },
};

describe('slotFor', () => {
    it('derives the cell slots from the region alone', () => {
        expect(slotFor('source', { type: 'select' })).toBe('node');
        expect(slotFor('default', { type: 'text' })).toBe('default');
    });

    it('puts every extras leaf in the options channel', () => {
        // An extras control writes one KEY of `options` — its handle — which is
        // why nothing there declares a slot either.
        expect(slotFor('extra', { type: 'select', handle: 'match' })).toBe('options');
    });
});

describe('channelsFor', () => {
    it('names the channels a container node binds', () => {
        expect(channelsFor({ type: 'subFields' })).toEqual(['fields']);
        expect(channelsFor({ type: 'matrixFields' })).toEqual(['blocks']);
        // One card over both halves of a related element's sub-fields.
        expect(channelsFor({ type: 'elementSubFields' })).toEqual(['fields', 'nativeFields']);
    });

    it('answers null for a leaf, which binds one value rather than a channel', () => {
        expect(channelsFor({ type: 'select' })).toBeNull();
        expect(channelsFor(undefined)).toBeNull();
    });
});

describe('setSlot', () => {
    it('writes a value and prunes an empty one', () => {
        expect(setSlot({ node: 'a' }, 'default', 'x')).toEqual({ node: 'a', default: 'x' });
        expect(setSlot({ node: 'a', default: 'x' }, 'default', '')).toEqual({ node: 'a' });
        expect(setSlot({ node: 'a', options: { m: 1 } }, 'options', {})).toEqual({ node: 'a' });
        expect(setSlot({ node: 'a', create: true }, 'create', false)).toEqual({ node: 'a' });
    });

    it('never mutates what it was given', () => {
        const mapping = { node: 'a' };

        setSlot(mapping, 'default', 'x');

        expect(mapping).toEqual({ node: 'a' });
    });
});

describe('readNode', () => {
    it('reads a cell slot, falling back to the node default and then null', () => {
        const mapping = { node: 'meta.headline', default: 'Untitled' };

        expect(readNode(mapping, 'source', sourceNode)).toBe('meta.headline');
        expect(readNode(mapping, 'default', { type: 'text' })).toBe('Untitled');
        expect(readNode({}, 'default', { type: 'text', default: 'x' })).toBe('x');
        expect(readNode({}, 'default', { type: 'text' })).toBeNull();
    });

    it('reads a sentinel back as the value that produced it', () => {
        // The stored shape has no node at all — the select still has to show the
        // row the operator picked rather than an empty box beside a raised flag.
        expect(readNode({ useDefault: true, default: 'Untitled' }, 'source', sourceNode)).toBe('__default__');
    });

    it('reads an extras leaf out of the options channel by handle', () => {
        expect(readNode({ options: { match: 'email' } }, 'extra', { handle: 'match', default: 'id' })).toBe('email');
        expect(readNode({}, 'extra', { handle: 'match', default: 'id' })).toBe('id');
    });

    it('keeps a stored false rather than falling back to the default', () => {
        // A lightswitch the operator turned OFF is a decision, not an absence.
        expect(readNode({ options: { create: false } }, 'extra', { handle: 'create', default: true })).toBe(false);
    });
});

describe('writeNode', () => {
    it('writes a cell slot', () => {
        expect(writeNode({}, 'default', { type: 'text' }, 'Untitled')).toEqual({ default: 'Untitled' });
    });

    it('prunes an emptied slot, leaving the mapping empty for its owner to drop', () => {
        expect(writeNode({ default: 'Untitled' }, 'default', { type: 'text' }, '')).toEqual({});
    });

    it('turns a sentinel pick into its flag instead of a node', () => {
        expect(writeNode({ node: 'meta.headline' }, 'source', sourceNode, '__default__'))
            .toEqual({ useDefault: true });
    });

    it('lowers the flag again when a real node is picked', () => {
        // The lowered flag rides along rather than vanishing — the server prunes it
        // back out on save.
        expect(writeNode({ useDefault: true }, 'source', sourceNode, 'meta.headline'))
            .toEqual({ useDefault: false, node: 'meta.headline' });
    });

    it('lowers the flag on the empty pick too', () => {
        // "— no mapping —" is a deliberate choice, so leaving "use default"
        // standing underneath it would keep writing a value the operator just told
        // the row not to write.
        expect(writeNode({ useDefault: true }, 'source', sourceNode, '')).toEqual({ useDefault: false });
    });

    it('merges an extras leaf into the options channel', () => {
        const next = writeNode(
            { node: 'by', options: { create: true } },
            'extra',
            { handle: 'match' },
            'email',
        );

        expect(next).toEqual({ node: 'by', options: { create: true, match: 'email' } });
    });

    it('drops an extras key rather than storing its empty', () => {
        const next = writeNode(
            { node: 'by', options: { create: true, match: 'email' } },
            'extra',
            { handle: 'create' },
            false,
        );

        expect(next).toEqual({ node: 'by', options: { match: 'email' } });
    });

    it('drops the options channel itself once its last key goes', () => {
        expect(writeNode({ node: 'by', options: { create: true } }, 'extra', { handle: 'create' }, false))
            .toEqual({ node: 'by' });
    });

    it('never mutates the mapping it was given', () => {
        const mapping = { node: 'meta.headline' };

        writeNode(mapping, 'source', sourceNode, '__default__');

        expect(mapping).toEqual({ node: 'meta.headline' });
    });
});

describe('the container arity', () => {
    const card = { type: 'elementSubFields', handle: 'nativeFields' };

    it('reads only the channels the node type binds', () => {
        const mapping = {
            node: 'by',
            fields: { importId: { node: 'by.id' } },
            nativeFields: { email: { node: 'by.email' } },
            blocks: { quote: {} },
        };

        expect(readChannels(mapping, card)).toEqual({
            fields: { importId: { node: 'by.id' } },
            nativeFields: { email: { node: 'by.email' } },
        });
    });

    it('reads an unsaved channel as empty rather than absent', () => {
        // The card renders a table either way, so it needs a map to read rows off.
        expect(readChannels({}, card)).toEqual({ fields: {}, nativeFields: {} });
    });

    it('writes back whichever channels the card emitted, leaving the rest', () => {
        const next = writeChannels(
            { node: 'by', options: { match: 'email' } },
            { fields: { importId: { node: 'by.id' } }, nativeFields: {} },
        );

        expect(next).toEqual({
            node: 'by',
            options: { match: 'email' },
            fields: { importId: { node: 'by.id' } },
        });
    });

    it('prunes an emptied channel', () => {
        expect(writeChannels({ fields: { col1: { node: 'specs.label' } } }, { fields: {} })).toEqual({});
    });
});
