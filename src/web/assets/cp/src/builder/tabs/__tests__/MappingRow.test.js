import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    fetchSample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    defaultOptions: vi.fn(),
    renderIconPicker: vi.fn(),
    configureCsrf: vi.fn(),
}));

import * as api from '../../api.js';
import { store } from '../../store.js';
import MappingRow from '../MappingRow.vue';
import MappingExtras from '../../schema/MappingExtras.vue';
import SearchableSelect from '../../../components/SearchableSelect.vue';
import fixture from '../../../../tests/fixtures/mappable-field.json';

/**
 * The extras' write path: a schema card's edits land in the right channel of
 * `link.mappings[handle]`, pruned to the shape Project Config stores. Specced
 * through the `fields` channel — a Table field's columns — because that channel
 * is the one a mapping row also carries sub-mappings in for a relation.
 */

// The source-node cell every mappable row declares — the preset
// MappingSchemaBuilder::sourceNode() emits, trimmed to the keys these specs read.
// The sample's discovered nodes are merged in beneath the sentinels by the
// renderer, since they're client state.
const SOURCE_REGION = [{
    type: 'select',
    allowCustom: true,
    searchable: true,
    sentinelOptions: [
        { value: '', label: '— no mapping —' },
        { value: '__default__', label: '— use default —' },
    ],
    sentinel: { __default__: 'useDefault' },
    optionsLabel: 'Nodes',
    optionsKind: 'node',
}];

// The option list an eager default select ships: PHP leads it with the blank row
// that keeps a picked default clearable (MappingSchemaBuilder::clearable()).
const EAGER_OPTIONS = [
    { value: '', label: '—' },
    { value: 'red', label: 'Red' },
    { value: 'green', label: 'Green' },
];

// A field with no cells of its own, whose extras are one subFields card — what
// fields\Table::schema() emits for a two-column table.
const tableField = {
    handle: 'specs',
    name: 'Specs',
    native: false,
    group: 'Content',
    mapping: {
        extra: [
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
    options: { elementTypes: [], processingActions: [], sites: [] },
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

        // The row declares no source cell, so the first SearchableSelect is the
        // first column's.
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

        expect(wrapper.findComponent(MappingExtras).props('mapping')).toEqual({});
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
        mapping: { source: SOURCE_REGION, default: [{ type: 'text' }] },
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

/**
 * The "auto" badge: this row's node came from Auto-match rather than a pick.
 * Rendered always and shown by the row's `data-auto` attribute, which is how
 * the CSS has switched it since the pre-SPA builder.
 */
describe('MappingRow auto badge', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    const autoMatchTitle = async () => {
        api.mappableFields.mockResolvedValue({ fields: [{ handle: 'title' }], groups: [], matchOptions: [] });
        await store.refreshMappableFields();

        api.fetchSample.mockResolvedValue({
            success: true,
            report: {
                itemCount: 1,
                flatNodes: [{ value: 'title', label: 'title' }],
                mappingSuggestions: [{ field: 'title', type: 'PlainText', node: 'title' }],
            },
        });
        await store.fetchSample();

        store.autoMatch();
    };

    const titleField = {
        handle: 'title',
        name: 'Title',
        native: true,
        group: 'Content',
        mapping: { source: SOURCE_REGION, default: [{ type: 'text' }] },
    };

    it('is off for a row the user mapped', async () => {
        await loadStore({ title: { node: 'meta.title' } });

        expect(mountRow(titleField).attributes('data-auto')).toBe('false');
    });

    it('is on for a row Auto-match filled', async () => {
        await loadStore();
        await autoMatchTitle();

        const wrapper = mountRow(titleField);
        expect(wrapper.attributes('data-auto')).toBe('true');
        expect(wrapper.find('.influx-auto-badge').exists()).toBe(true);
    });

    it('goes off the moment the user picks a node themselves', async () => {
        await loadStore();
        await autoMatchTitle();
        const wrapper = mountRow(titleField);

        wrapper.findComponent(SearchableSelect).vm.$emit('update:modelValue', 'meta.headline');
        await wrapper.vm.$nextTick();

        expect(wrapper.attributes('data-auto')).toBe('false');
        expect(store.ui.autoMatched).toEqual([]);
    });
});

/**
 * A default list a strategy declared `lazy` rather than shipping inline. Country
 * is the case: ~250 options that would otherwise ride every builder bootstrap
 * once per Country field on the layout, whether or not the row is ever opened.
 */
describe('MappingRow lazy default options', () => {
    const lazyField = {
        handle: 'test_country',
        name: 'Country',
        native: false,
        group: 'Content',
        fieldClass: 'craft\\fields\\Country',
        mapping: { source: SOURCE_REGION, default: [{ type: 'select', lazy: true, searchable: true, searchPlaceholder: 'Search options…' }] },
    };

    const eagerField = {
        handle: 'test_dropdown',
        name: 'Dropdown',
        native: false,
        group: 'Content',
        fieldClass: 'craft\\fields\\Dropdown',
        mapping: { source: SOURCE_REGION, default: [{ type: 'select', searchable: true, options: EAGER_OPTIONS }] },
    };

    // The row's default cell — the second select, after the source-node one.
    const defaultSelect = (wrapper) => wrapper.findAllComponents(SearchableSelect)[1];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('fetches nothing until the row is opened', async () => {
        await loadStore();
        mountRow(lazyField);

        expect(api.defaultOptions).not.toHaveBeenCalled();
    });

    it('fetches once on the first open, keyed by the field handle', async () => {
        api.defaultOptions.mockResolvedValue({ options: [{ value: 'BE', label: 'Belgium' }] });
        await loadStore();
        const wrapper = mountRow(lazyField);

        defaultSelect(wrapper).vm.$emit('open');
        await flushPromises();

        expect(api.defaultOptions).toHaveBeenCalledExactlyOnceWith('test_country');
        expect(defaultSelect(wrapper).props('options')).toEqual([{ value: 'BE', label: 'Belgium' }]);
    });

    it('does not refetch on a second open', async () => {
        api.defaultOptions.mockResolvedValue({ options: [{ value: 'BE', label: 'Belgium' }] });
        await loadStore();
        const wrapper = mountRow(lazyField);

        defaultSelect(wrapper).vm.$emit('open');
        await flushPromises();
        defaultSelect(wrapper).vm.$emit('open');
        await flushPromises();

        expect(api.defaultOptions).toHaveBeenCalledTimes(1);
    });

    it('leaves an eager field alone — its options came with the descriptor', async () => {
        await loadStore();
        const wrapper = mountRow(eagerField);

        defaultSelect(wrapper).vm.$emit('open');
        await flushPromises();

        expect(api.defaultOptions).not.toHaveBeenCalled();
        expect(defaultSelect(wrapper).props('options')).toEqual([
            { value: '', label: '—' },
            { value: 'red', label: 'Red' },
            { value: 'green', label: 'Green' },
        ]);
    });

    it('survives a failed fetch with an empty list rather than throwing', async () => {
        api.defaultOptions.mockRejectedValue(new Error('boom'));
        vi.spyOn(console, 'error').mockImplementation(() => {});
        await loadStore();
        const wrapper = mountRow(lazyField);

        defaultSelect(wrapper).vm.$emit('open');
        await flushPromises();

        expect(defaultSelect(wrapper).props('options')).toEqual([]);
    });
});

/**
 * An Icon field's default cell mounts Craft's own picker rather than a list of
 * its own — the icon set is thousands of entries Craft already searches
 * server-side. Same trade, and same fieldHandle contract, as the element default.
 */
describe('MappingRow icon default', () => {
    const iconField = {
        handle: 'test_icon',
        name: 'Icon',
        native: false,
        group: 'Content',
        fieldClass: 'craft\\fields\\Icon',
        mapping: { source: SOURCE_REGION, default: [{ type: 'icon' }] },
    };

    const iconPicker = (wrapper) => wrapper.findComponent({ name: 'IconPicker' });

    const mountIconRow = (field = iconField, mappings = {}) => mount(MappingRow, {
        props: { field, nodeOptions: [] },
        global: { mocks: { $t: (s) => s } },
        // Craft.IconPicker lives in the CP, not the test env — the contract under
        // test is what the row hands it and stores back.
        shallow: false,
        ...mappings,
    });

    beforeEach(() => {
        vi.clearAllMocks();
        api.renderIconPicker.mockResolvedValue({ html: '', jsSettings: { id: 'x', freeOnly: true } });
    });

    it('renders the icon picker rather than a text box or a select', async () => {
        await loadStore();
        const wrapper = mountIconRow();

        expect(iconPicker(wrapper).exists()).toBe(true);
        expect(wrapper.findComponent(SearchableSelect).exists()).toBe(true); // the node cell only
        expect(wrapper.find('input.text').exists()).toBe(false);
    });

    it('names the field so the server can derive its Pro gating', async () => {
        await loadStore();

        expect(iconPicker(mountIconRow()).props('fieldHandle')).toBe('test_icon');
    });

    it('seeds the picker with the saved default', async () => {
        await loadStore({ test_icon: { default: 'user' } });

        expect(iconPicker(mountIconRow()).props('modelValue')).toBe('user');
    });

    it('stores the picked icon name', async () => {
        await loadStore();
        const wrapper = mountIconRow();

        iconPicker(wrapper).vm.$emit('update:modelValue', 'house');

        expect(store.link.mappings.test_icon).toEqual({ default: 'house' });
    });

    it('prunes the default away when the icon is removed', async () => {
        await loadStore({ test_icon: { default: 'user' } });
        const wrapper = mountIconRow();

        iconPicker(wrapper).vm.$emit('update:modelValue', null);

        expect(store.link.mappings.test_icon).toBeUndefined();
    });
});

/**
 * A field nothing can be mapped to — a Preparse field, whose value is re-rendered
 * from a template on every element save. The row keeps its label and says why,
 * rather than vanishing: an operator looking for the field should find it and
 * learn why it isn't mappable.
 */
describe('MappingRow unmappable field', () => {
    const computedField = {
        handle: 'test_preparse',
        name: 'Preparse',
        native: false,
        group: 'Content',
        fieldClass: 'jalendport\\preparse\\fields\\PreparseFieldType',
        // The note takes the SOURCE region — the cell the node select would have
        // occupied — and there is no default region at all. That placement IS the
        // declaration: no flag, and no inline-vs-collapsible rule, because a note
        // renders wherever its region does.
        mapping: { source: [{ type: 'note', text: 'This field can’t be mapped, its value is computed from a template.' }] },
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the notice instead of a source-node cell or a default cell', async () => {
        await loadStore();
        const wrapper = mountRow(computedField);

        expect(wrapper.text()).toContain('computed from a template');
        expect(wrapper.findComponent(SearchableSelect).exists()).toBe(false);
        expect(wrapper.find('input.text').exists()).toBe(false);
    });

    /**
     * With no default cell beside it, the source cell spans the column the default
     * would have taken rather than sitting squeezed in the middle — keyed on the
     * absent region, so the layout can't drift from the cells.
     */
    it('spans the cell the default would have taken, with no collapsible extras', async () => {
        await loadStore();
        const wrapper = mountRow(computedField);

        expect(wrapper.find('.influx-cell-span').findComponent({ name: 'NoteField' }).exists()).toBe(true);
        expect(wrapper.find('.influx-mapping-extras').exists()).toBe(false);
        expect(wrapper.find('.influx-mapping-extras-slot').exists()).toBe(false);
    });

    it('still labels the row, so the field is findable', async () => {
        await loadStore();
        const wrapper = mountRow(computedField);

        expect(wrapper.text()).toContain('Preparse');
        expect(wrapper.find('code.handle').text()).toBe('test_preparse');
    });
});

/**
 * The multi-value option fields (Checkboxes, MultiSelect) hold a LIST, so their
 * default does too — they used to be offered the same single-value picker a
 * Dropdown gets, where a default could only ever set one of the boxes.
 */
describe('MappingRow multi-value default', () => {
    const multiField = {
        handle: 'test_checkboxes',
        name: 'Checkboxes',
        native: false,
        group: 'Content',
        fieldClass: 'craft\\fields\\Checkboxes',
        mapping: { source: SOURCE_REGION, default: [{ type: 'multiSelect', searchable: true, options: [{ value: 'red', label: 'Red' }, { value: 'green', label: 'Green' }] }] },
    };

    const defaultSelect = (wrapper) => wrapper.findAllComponents(SearchableSelect)[1];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the default picker in multiple mode', async () => {
        await loadStore();

        expect(defaultSelect(mountRow(multiField)).props('multiple')).toBe(true);
    });

    it('offers no blank row — "none" is having nothing picked', async () => {
        await loadStore();

        expect(defaultSelect(mountRow(multiField)).props('options')).toEqual([
            { value: 'red', label: 'Red' },
            { value: 'green', label: 'Green' },
        ]);
    });

    it('stores the picked list', async () => {
        await loadStore();
        const wrapper = mountRow(multiField);

        defaultSelect(wrapper).vm.$emit('update:modelValue', ['red', 'green']);

        expect(store.link.mappings.test_checkboxes).toEqual({ default: ['red', 'green'] });
    });

    it('prunes the default away when the last pick is removed', async () => {
        await loadStore({ test_checkboxes: { default: ['red'] } });
        const wrapper = mountRow(multiField);

        defaultSelect(wrapper).vm.$emit('update:modelValue', null);

        expect(store.link.mappings.test_checkboxes).toBeUndefined();
    });

    it('still gives a single-value option field one blank row and no multiple', async () => {
        await loadStore();
        const single = {
            ...multiField,
            handle: 'test_dropdown',
            mapping: { source: SOURCE_REGION, default: [{ type: 'select', searchable: true, options: EAGER_OPTIONS }] },
        };
        const wrapper = mountRow(single);

        expect(defaultSelect(wrapper).props('multiple')).toBe(false);
        expect(defaultSelect(wrapper).props('options')[0]).toEqual({ value: '', label: '—' });
    });
});
