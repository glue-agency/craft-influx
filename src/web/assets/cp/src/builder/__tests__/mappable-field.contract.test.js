import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    fetchSample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    configureCsrf: vi.fn(),
}));

import * as api from '../api.js';
import { store } from '../store.js';
import MappingRow from '../tabs/MappingRow.vue';
import fixture from '../../../tests/fixtures/mappable-field.json';

/**
 * The JS half of the mappable-field contract test. PHP is the authority
 * (schema\MappableField::toArray() — asserted against the same fixture by
 * MappableFieldPayloadTest); this side asserts the fixture satisfies what the
 * builder actually READS off a descriptor: the key set types.js documents, and
 * every property MappingRow / MappingGroup branch on. Drift on either side fails
 * one of the two suites.
 *
 * There is no visibility flag on a descriptor: a native the element type hides —
 * like a custom field removed from its layout — simply isn't reported, so
 * everything the builder receives is mappable.
 */

const REQUIRED_KEYS = ['handle', 'name', 'native', 'group', 'mapping'];
const OPTIONAL_KEYS = ['fieldClass'];
const REGIONS = ['source', 'default', 'extra'];
// Every type the control registry claims. Leaf controls bind one value; the three
// containers bind whole stored channels (see lib/slots.js).
const LEAF_TYPES = ['text', 'code', 'select', 'multiSelect', 'lightswitch', 'tokenInput', 'element', 'icon', 'note'];
const CONTROL_TYPES = [...LEAF_TYPES, 'subFields', 'elementSubFields', 'matrixFields'];

const byHandle = (handle) => fixture.find((f) => f.handle === handle);

const mountRow = (field) => mount(MappingRow, {
    props: { field, nodeOptions: [{ value: 'id', label: 'id' }] },
    global: {
        mocks: { $t: (s) => s },
        stubs: { SearchableSelect: true, ElementPicker: true },
    },
});

describe('Mappable field wire contract', () => {
    it('carries the keys types.js documents, and no others', () => {
        expect(fixture.length).toBeGreaterThan(0);

        for (const field of fixture) {
            expect(Object.keys(field)).toEqual(expect.arrayContaining(REQUIRED_KEYS));

            const extra = Object.keys(field).filter((k) => !REQUIRED_KEYS.includes(k));
            expect(extra.every((k) => OPTIONAL_KEYS.includes(k))).toBe(true);
        }
    });

    it('shapes the values the mapper assumes', () => {
        for (const field of fixture) {
            expect(typeof field.handle).toBe('string');
            expect(typeof field.name).toBe('string');
            expect(typeof field.native).toBe('boolean');
            expect(typeof field.group).toBe('string');
        }
    });

    it('marks a custom field by its fieldClass, and a native by having none', () => {
        // Not decoration: `fieldClass` is what decides whether a row sends its
        // handle to the server-rendered pickers, and a native must send nothing or a
        // real custom field handled `author` would reshape the native author's.
        expect(byHandle('importId').native).toBe(false);
        expect(typeof byHandle('importId').fieldClass).toBe('string');

        expect(byHandle('author').native).toBe(true);
        expect(byHandle('author')).not.toHaveProperty('fieldClass');
    });

    it('describes the whole row as regions of schema nodes, and nothing else', () => {
        // THE contract: one key per region the row renders, each a list of nodes the
        // registry dispatches by `type`. An ABSENT region is an absent cell, which is
        // why there is no `defaultType` beside this and no `subfieldsOnly` flag under
        // it — both said by convention what this says by structure.
        for (const field of fixture) {
            expect(Object.keys(field.mapping).length).toBeGreaterThan(0);

            for (const [region, nodes] of Object.entries(field.mapping)) {
                expect(REGIONS).toContain(region);
                expect(nodes.length).toBeGreaterThan(0);
                nodes.forEach((node) => expect(CONTROL_TYPES).toContain(node.type));
            }
        }
    });

    it('declares the source cell as one control over two slots', () => {
        // Picking the sentinel row writes the mapping's `useDefault` flag rather
        // than a node — the one thing a region can't imply on its own.
        const [source] = byHandle('title').mapping.source;

        expect(source.allowCustom).toBe(true);
        expect(source.sentinel).toEqual({ __default__: 'useDefault' });
        expect(source.sentinelOptions.map((o) => o.value)).toEqual(['', '__default__']);
    });

    it('lets each field name its own default control', () => {
        // An element picker is not a native-only privilege: a custom Entries field
        // picks entries the way the native author picks users, because both declare
        // the same node type.
        expect(byHandle('title').mapping.default).toEqual([{ type: 'text', handle: 'title' }]);

        const [enabled] = byHandle('enabled').mapping.default;
        expect(enabled.type).toBe('select');
        // The field's own values only — "nothing picked" is a sentinel beside the
        // list, exactly as the source cell declares its two.
        expect(enabled.options.map((o) => o.value)).toEqual(['true', 'false']);
        expect(enabled.sentinelOptions).toEqual([{ value: '', label: '— no default —' }]);

        const [author] = byHandle('author').mapping.default;
        expect(author.type).toBe('element');
        expect(author.elementType).toBe('craft\\elements\\User');

        const [related] = byHandle('relatedArticles').mapping.default;
        expect(related.type).toBe('element');
        expect(related.elementType).toBe('craft\\elements\\Entry');
    });

    it('says a row has no cells of its own by declaring neither region', () => {
        expect(Object.keys(byHandle('contentBlocks').mapping)).toEqual(['extra']);
    });

    it('types every sub-field row, and names what an element row picks', () => {
        // A sub-field row IS a node, dispatched through the same registry a cell goes
        // through — before that, every row was a text box, including a relation's.
        const rows = byHandle('relatedArticles').mapping.extra
            .find((node) => node.type === 'elementSubFields').subFields;

        for (const row of rows) {
            // A row is one cell, so it can only ever be a leaf — a card inside a
            // card would be nesting for nothing.
            expect(LEAF_TYPES).toContain(row.type);
            expect(typeof row.handle).toBe('string');
            expect(typeof row.label).toBe('string');
        }

        const campus = rows.find((row) => row.type === 'element');
        expect(campus.elementType).toBe('craft\\elements\\Entry');
    });

    it('marks only the custom rows with a channel', () => {
        // One card, two channels: an absent key means `nativeFields` — the channel
        // these rows were stored in before the key existed, and the one an element
        // attribute has to be written through.
        const rows = byHandle('relatedArticles').mapping.extra
            .find((node) => node.type === 'elementSubFields').subFields;

        const channels = Object.fromEntries(rows.map((row) => [row.handle, row.channel]));
        expect(channels).toEqual({
            title: undefined,
            slug: undefined,
            blurb: 'fields',
            campus: 'fields',
        });
    });

    it('carries no visibility flag — everything reported is mappable', () => {
        for (const field of fixture) {
            expect(field).not.toHaveProperty('offered');
            expect(field).not.toHaveProperty('hidden');
        }
    });
});

describe('MappingRow reads the contract', () => {
    beforeEach(async () => {
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue({
            link: { handle: 'articles', elementType: 'craft\\elements\\Entry', elementCriteria: {}, mappings: {} },
            options: {},
            meta: { isNew: false },
        });
        api.mappableFields.mockResolvedValue({ fields: fixture, groups: [], matchOptions: [] });
        api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
        await store.load(1);
    });

    it('renders a plain native from handle + name alone', () => {
        const row = mountRow(byHandle('title'));

        expect(row.find('.name').text()).toBe('Title');
        expect(row.find('code.handle').text()).toBe('title');
        expect(row.vm.hasDefaultCell).toBe(true);
        expect(row.vm.hasExtras).toBe(false);
    });

    it('renders each cell from the node its own region declares', () => {
        // The row looks each node's type up in the registry and binds the region's
        // slot to it — the row itself reads nothing about the control. Both cells
        // go through the same renderer, which is the whole point.
        const cells = mountRow(byHandle('enabled')).findAllComponents({ name: 'MappingCell' });

        expect(cells.map((cell) => cell.props('region'))).toEqual(['source', 'default']);

        const [source, dflt] = cells.map((cell) => cell.findComponent({ name: 'SelectField' }));

        expect(source.props('node').sentinel).toEqual({ __default__: 'useDefault' });
        expect(dflt.props('node').options).toEqual([
            { value: 'true', label: 'Enabled' },
            { value: 'false', label: 'Disabled' },
        ]);
        // Both cells reach SearchableSelect through a sentinel group, so both read
        // as an empty control until something is picked.
        expect(dflt.vm.resolvedOptions[0].options).toEqual([{ value: '', label: '— no default —' }]);
    });

    it('drives the extras block off the `extra` region', () => {
        const row = mountRow(byHandle('author'));

        expect(row.vm.extraNodes).toEqual(byHandle('author').mapping.extra);
        expect(row.vm.hasExtras).toBe(true);
    });

    it('renders no cells for a row whose value comes from its sub-mappings', () => {
        // Declaring neither region IS the statement — there is no flag for the row
        // to read, and the extras card is all it renders.
        const row = mountRow(byHandle('contentBlocks'));

        expect(row.vm.sourceNodes).toEqual([]);
        expect(row.vm.hasDefaultCell).toBe(false);
        expect(row.vm.hasExtras).toBe(true);
    });

    it('renders the element picker off the node type alone, native or not', () => {
        // Nothing in the cell asks whether the descriptor is a native — only
        // whether it has a fieldClass to shape the picker after.
        const row = mountRow(byHandle('relatedArticles'));
        const picker = row.findComponent({ name: 'ElementPicker' });

        expect(picker.exists()).toBe(true);
        expect(picker.props('elementType')).toBe('craft\\elements\\Entry');
    });

    it('treats a descriptor with no extra region as extras-less', () => {
        const row = mountRow(byHandle('importId'));

        expect(row.vm.extraNodes).toEqual([]);
        expect(row.vm.hasExtras).toBe(false);
    });
});
