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
 * The row-level "Clear": one press wipes the field's whole mapping, extras
 * included. The row is the largest scope this can work at — it owns the
 * extras models it re-seeds, which sibling rows (seeded once in data()) do
 * not, hence no group- or link-level equivalent.
 */
describe('MappingRow clear', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    const clearBtn = (wrapper) => wrapper.find('.clear-mapping');

    it('shows the button only for an editable row with something saved', async () => {
        await loadStore();
        expect(clearBtn(mountRow()).exists()).toBe(false);

        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });
        expect(clearBtn(mountRow()).exists()).toBe(true);

        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } }, { readOnly: true });
        expect(clearBtn(mountRow()).exists()).toBe(false);
    });

    it('drops the whole handle out of the store', async () => {
        await loadStore({
            specs: { default: 'x', options: { format: 'raw' }, fields: { col1: { node: 'specs.label' } } },
            title: { node: 'meta.title' },
        });
        const wrapper = mountRow();

        await clearBtn(wrapper).trigger('click');

        expect(store.link.mappings).toEqual({ title: { node: 'meta.title' } });
    });

    it('re-seeds the extras models so the sub-field cards redraw empty', async () => {
        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });
        const wrapper = mountRow();

        expect(wrapper.findAllComponents(SearchableSelect).at(0).props('modelValue')).toBe('specs.label');

        await clearBtn(wrapper).trigger('click');

        expect(wrapper.vm.extrasFields).toEqual({});
        expect(wrapper.findAllComponents(SearchableSelect).at(0).props('modelValue')).toBe('');
    });

    it('leaves the extras panel open — the press is not a toggle', async () => {
        await loadStore({ specs: { fields: { col1: { node: 'specs.label' } } } });
        const wrapper = mountRow();
        expect(wrapper.vm.extrasExpanded).toBe(true);

        await clearBtn(wrapper).trigger('click');

        expect(wrapper.vm.extrasExpanded).toBe(true);
        expect(wrapper.find('.influx-mapping-extras').attributes('data-expanded')).toBe('true');
    });
});
