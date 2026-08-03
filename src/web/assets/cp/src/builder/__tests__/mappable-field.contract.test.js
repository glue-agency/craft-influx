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

const REQUIRED_KEYS = ['handle', 'name', 'native', 'group', 'defaultType'];
const OPTIONAL_KEYS = ['options', 'elementType', 'fieldClass', 'fieldMeta'];
const DEFAULT_TYPES = ['text', 'select', 'element'];
const SUB_FIELD_TYPES = ['text', 'code', 'select', 'element'];

const byHandle = (handle) => fixture.find((f) => f.handle === handle);

const mountRow = (field) => mount(MappingRow, {
    props: { field, nodeOptions: [{ value: 'id', label: 'id' }] },
    global: {
        mocks: { $t: (s) => s },
        stubs: { SearchableSelect: true, ElementPicker: true, SchemaForm: true },
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
            expect(DEFAULT_TYPES).toContain(field.defaultType);
        }
    });

    it('pairs each optional key with the defaultType that needs it', () => {
        // The row's default-value editor branches on defaultType; a select needs
        // its value => label map and an element picker needs its FQCN.
        expect(byHandle('enabled').defaultType).toBe('select');
        expect(byHandle('enabled').options).toEqual({ true: 'Enabled', false: 'Disabled' });

        expect(byHandle('author').defaultType).toBe('element');
        expect(typeof byHandle('author').elementType).toBe('string');

        // Custom fields identify their kind through fieldClass + fieldMeta.
        expect(byHandle('importId').native).toBe(false);
        expect(typeof byHandle('importId').fieldClass).toBe('string');
        expect(Array.isArray(byHandle('importId').fieldMeta.schema)).toBe(true);
    });

    it('lets a custom field carry an element default editor too', () => {
        // The default editor is the field strategy's call, not a native-only
        // privilege: an Entries field picks entries the way the author picks
        // users, and still identifies its kind through fieldClass + fieldMeta.
        const related = byHandle('relatedArticles');

        expect(related.native).toBe(false);
        expect(related.defaultType).toBe('element');
        expect(related.elementType).toBe('craft\\elements\\Entry');
        expect(typeof related.fieldClass).toBe('string');
    });

    it('types every sub-field row, and names what an element row picks', () => {
        // A row's default editor branches on its own `type` the way the
        // top-level row branches on `defaultType` — before that, every row was
        // a text box, including a relation's.
        const rows = byHandle('relatedArticles').fieldMeta.schema
            .find((node) => node.type === 'elementSubFields').subFields;

        for (const row of rows) {
            expect(SUB_FIELD_TYPES).toContain(row.type);
            expect(typeof row.handle).toBe('string');
            expect(typeof row.label).toBe('string');
        }

        const campus = rows.find((row) => row.type === 'element');
        expect(campus.elementType).toBe('craft\\elements\\Entry');
    });

    it('marks only the custom rows with a channel', () => {
        // One card, two channels: an absent key means `nativeFields` — the
        // channel these rows were stored in before the key existed, and the one
        // an element attribute has to be written through.
        const rows = byHandle('relatedArticles').fieldMeta.schema
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
        expect(row.vm.subfieldsOnly).toBe(false);
        expect(row.vm.hasExtras).toBe(false);
    });

    it('builds the select default editor from `options`', () => {
        const row = mountRow(byHandle('enabled'));

        expect(row.vm.defaultSelectOptions).toEqual([
            { value: '', label: '—' },
            { value: 'true', label: 'Enabled' },
            { value: 'false', label: 'Disabled' },
        ]);
    });

    it('drives the extras subform off `fieldMeta.schema`', () => {
        const row = mountRow(byHandle('author'));

        expect(row.vm.extrasSchema).toEqual(byHandle('author').fieldMeta.schema);
        expect(row.vm.hasExtras).toBe(true);
    });

    it('reads `fieldMeta.subfieldsOnly` as "no node or default of its own"', () => {
        const row = mountRow(byHandle('contentBlocks'));

        expect(row.vm.subfieldsOnly).toBe(true);
        expect(row.vm.hasExtras).toBe(true);
    });

    it('renders the element picker off `defaultType` alone, native or not', () => {
        // The row branches on defaultType only — nothing in the editor cell
        // asks whether the descriptor is a native.
        const row = mountRow(byHandle('relatedArticles'));
        const picker = row.findComponent({ name: 'ElementPicker' });

        expect(picker.exists()).toBe(true);
        expect(picker.props('elementType')).toBe('craft\\elements\\Entry');
    });

    it('treats a descriptor with no fieldMeta as extras-less', () => {
        const row = mountRow(byHandle('importId'));

        expect(row.vm.extrasSchema).toEqual([]);
        expect(row.vm.hasExtras).toBe(false);
    });
});
