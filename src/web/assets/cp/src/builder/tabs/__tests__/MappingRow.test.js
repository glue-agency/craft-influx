import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    fetchSample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    configureCsrf: vi.fn(),
}));

import * as api from '../../api.js';
import { store } from '../../store.js';
import MappingRow from '../MappingRow.vue';
import SchemaForm from '../../schema/SchemaForm.vue';
import SearchableSelect from '../../SearchableSelect.vue';
import fixture from '../../../../tests/fixtures/mappable-field.json';

/**
 * The extras' write path: a schema card's edits land in the right channel of
 * `link.mappings[handle]`, pruned to the shape Project Config stores. Specced
 * through the `fields` channel — a Table field's columns — because that channel
 * is the one a mapping row also carries sub-mappings in for a relation.
 */

// A subfieldsOnly field whose schema is one subFields card, i.e. what
// fields\Table::schema() emits for a two-column table.
const tableField = {
    handle: 'specs',
    name: 'Specs',
    native: false,
    group: 'Content',
    defaultType: 'text',
    fieldMeta: {
        subfieldsOnly: true,
        schema: [
            { type: 'subFields', handle: 'fields', label: 'Columns', subFields: [
                { type: 'text', handle: 'col1', label: 'Label' },
                { type: 'text', handle: 'col2', label: 'Value' },
            ] },
        ],
    },
};

const bootstrapPayload = (mappings = {}, meta = {}) => ({
    link: {
        handle: 'articles',
        name: 'Articles',
        elementType: 'craft\\elements\\Entry',
        elementCriteria: {},
        endpoint: null,
        siteEndpoints: [],
        offset: {},
        mappings,
    },
    options: { elementTypes: [], sections: [], sectionEntryTypes: {}, processingActions: [], sites: [] },
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x', envSuggestions: [], ...meta },
});

const loadStore = async (mappings = {}, meta = {}) => {
    api.bootstrap.mockResolvedValue(bootstrapPayload(mappings, meta));
    api.mappableFields.mockResolvedValue({ fields: [], groups: [], matchOptions: [] });
    api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
    await store.load(1);
};

const mountRow = (field = tableField) => mount(MappingRow, {
    props: { field, nodeOptions: [{ value: 'specs.label', label: 'specs.label' }] },
    global: { mocks: { $t: (s) => s }, stubs: { ElementPicker: true } },
});

const descriptor = (handle) => fixture.find((f) => f.handle === handle);

const picker = (wrapper) => wrapper.findComponent({ name: 'ElementPicker' });

describe('MappingRow fields extras', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('writes a column row into the mapping\'s fields channel', async () => {
        await loadStore();
        const wrapper = mountRow();

        // subfieldsOnly hides the row's own source-node select, so the first
        // SearchableSelect is the first column's.
        wrapper.findAllComponents(SearchableSelect).at(0).vm.$emit('update:modelValue', 'specs.label');

        expect(store.link.mappings.specs).toEqual({
            fields: { col1: { node: 'specs.label' } },
        });
    });

    it('seeds the card from the saved mapping and merges further edits', async () => {
        await loadStore({ specs: { fields: { col2: { node: 'specs.value' } } } });
        const wrapper = mountRow();

        wrapper.findAllComponents(SearchableSelect).at(0).vm.$emit('update:modelValue', 'specs.label');

        expect(store.link.mappings.specs.fields).toEqual({
            col2: { node: 'specs.value' },
            col1: { node: 'specs.label' },
        });
    });

    it('prunes the whole row away once its last column is cleared', async () => {
        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });
        const wrapper = mountRow();

        wrapper.findAllComponents(SearchableSelect).at(0).vm.$emit('update:modelValue', '');

        // An emptied channel drops off the mapping, and a mapping with nothing
        // left drops out of `mappings` — no noise keys in Project Config.
        expect(store.link.mappings.specs).toBeUndefined();
    });
});

/**
 * The element default editor: which field the picker is shaped after, and what
 * the row stores of what it picks.
 *
 * Only a CUSTOM descriptor names its field — `fieldClass` is what makes one
 * custom — because the server looks the handle up among the real custom fields.
 * A native row that sent its handle would let a custom field called 'author'
 * reshape the native author's picker.
 */
describe('MappingRow element default', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('names the field for a custom descriptor', async () => {
        await loadStore();

        expect(picker(mountRow(descriptor('relatedArticles'))).props('fieldHandle')).toBe('relatedArticles');
    });

    it('names no field for a native descriptor', async () => {
        await loadStore();

        expect(picker(mountRow(descriptor('author'))).props('fieldHandle')).toBe(null);
    });

    it('stores a multi-relation pick as the list the picker emits', async () => {
        await loadStore();
        const wrapper = mountRow(descriptor('relatedArticles'));

        picker(wrapper).vm.$emit('update:modelValue', ['12', '34']);

        expect(store.link.mappings.relatedArticles).toEqual({ default: ['12', '34'] });
    });

    it('prunes the default away when the picker clears', async () => {
        await loadStore({ relatedArticles: { default: ['12', '34'] } });
        const wrapper = mountRow(descriptor('relatedArticles'));

        picker(wrapper).vm.$emit('update:modelValue', null);

        expect(store.link.mappings.relatedArticles).toBeUndefined();
    });
});

/**
 * Clearing is a group-header affordance only — the row carries none. What
 * the row owes that header is redrawing off the store for a wipe it did not
 * perform itself.
 */
describe('MappingRow clear', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('carries no clear button of its own', async () => {
        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });

        expect(mountRow().find('.clear-mapping').exists()).toBe(false);
    });

    it('redraws the sub-field cards for a wipe the row did not perform', async () => {
        // The regression the group header's Clear rests on: it rewrites
        // `mappings` for handles other than its own rows', so every row has
        // to redraw off the store on its own. Seeding the extras models in
        // data() — as this row used to — left them rendering sub-field
        // handles the wipe had already dropped.
        await loadStore({
            specs: { options: { format: 'raw' }, fields: { col1: { node: 'specs.label' } } },
        });
        const wrapper = mountRow();

        expect(wrapper.findAllComponents(SearchableSelect).at(0).props('modelValue')).toBe('specs.label');

        store.link.mappings = {};
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(SchemaForm).props('fields')).toEqual({});
        expect(wrapper.findComponent(SchemaForm).props('options')).toEqual({});
        expect(wrapper.findAllComponents(SearchableSelect).at(0).props('modelValue')).toBe('');
    });

    it('leaves the extras panel open through the wipe', async () => {
        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });
        const wrapper = mountRow();
        expect(wrapper.vm.extrasExpanded).toBe(true);

        store.link.mappings = {};
        await wrapper.vm.$nextTick();

        expect(wrapper.vm.extrasExpanded).toBe(true);
        expect(wrapper.find('.influx-mapping-extras').attributes('data-expanded')).toBe('true');
    });
});

/**
 * A node the sample doesn't carry can still be mapped: the picker mints the
 * typed path (SearchableSelect's `allowCustom`). The sample is ONE page, so a
 * key that only appears further in is a legitimate mapping — it just reads as
 * missing until a page carrying it is fetched.
 *
 * Last in the file on purpose: the store is a module singleton and its sample
 * survives a re-load, so priming one here can't leak into the specs above.
 */
describe('MappingRow custom source node', () => {
    const plainField = {
        handle: 'title',
        name: 'Title',
        native: true,
        group: 'Content',
        defaultType: 'text',
        fieldMeta: {},
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('lets its node picker mint one', async () => {
        await loadStore();

        expect(mountRow(plainField).findComponent(SearchableSelect).props('allowCustom')).toBe(true);
    });

    it('saves the minted path and flags the row missing', async () => {
        await loadStore();
        api.fetchSample.mockResolvedValue({
            success: true,
            report: { flatNodes: [{ value: 'specs.label', label: 'specs.label' }] },
        });
        await store.fetchSample();
        const wrapper = mountRow(plainField);

        wrapper.findComponent(SearchableSelect).vm.$emit('update:modelValue', 'consultations.name');
        await wrapper.vm.$nextTick();

        // `useDefault: false` rides along on every node pick — the server
        // prunes it back out on save.
        expect(store.link.mappings.title).toEqual({ node: 'consultations.name', useDefault: false });
        expect(wrapper.find('.influx-missing-badge').exists()).toBe(true);
    });
});
