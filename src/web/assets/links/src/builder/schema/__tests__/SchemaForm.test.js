import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SchemaForm from '../SchemaForm.vue';
import MatrixFields from '../inputs/MatrixFields.vue';
import SearchableSelect from '../../SearchableSelect.vue';

/**
 * Locks in the renderer contract for PHP-declared schemas (BuilderSchema):
 * dispatch purely by node type, display-only defaults, showIf visibility,
 * and the two emit channels (options vs nativeFields).
 */

const assetSchema = [
    { type: 'select', handle: 'mode', label: 'Value is', default: 'id', options: [
        { value: 'id', label: 'Asset ID' },
        { value: 'url', label: 'URL' },
    ] },
    { type: 'lightswitch', handle: 'upload', label: 'Download missing', showIf: [{ handle: 'mode', equals: 'url' }] },
    { type: 'text', handle: 'volume', label: 'Target volume', showIf: [
        { handle: 'mode', equals: 'url' },
        { handle: 'upload' },
    ] },
    { type: 'elementSubFields', handle: 'nativeFields', label: 'Asset sub-fields', subFields: [
        { type: 'text', handle: 'alt', label: 'Alt text' },
    ] },
];

const mountForm = (props = {}) => mount(SchemaForm, {
    props: {
        schema: assetSchema,
        options: {},
        nativeFields: {},
        nodeOptions: [{ value: 'images.0.alt', label: 'images.0.alt' }],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

describe('SchemaForm', () => {
    it('renders by node type and applies display-only defaults', () => {
        const wrapper = mountForm();
        // Grid-layout selects render as SearchableSelect (the node select's
        // chrome); the default value resolves to its option label.
        const select = wrapper.findComponent(SearchableSelect);
        expect(select.props('modelValue')).toBe('id');
        expect(select.find('.influx-searchable-select-trigger .value').text()).toBe('Asset ID');
        // Untouched defaults must never be emitted into the saved options.
        expect(wrapper.emitted('update:options')).toBeUndefined();
    });

    it('renders code nodes as monospace text inputs', () => {
        const wrapper = mountForm({
            schema: [{ type: 'code', handle: 'token', label: 'Token' }],
        });
        const input = wrapper.find('input[type="text"]');
        expect(input.classes()).toContain('code');
    });

    it('hides nodes whose showIf conditions fail — including chained ones', async () => {
        const wrapper = mountForm();
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);

        await wrapper.setProps({ options: { mode: 'url' } });
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true);
        // volume needs mode=url AND upload truthy
        expect(wrapper.findAll('input[type="text"].text')).toHaveLength(1); // sub-field default only

        await wrapper.setProps({ options: { mode: 'url', upload: true } });
        expect(wrapper.findAll('input[type="text"].text')).toHaveLength(2);
    });

    it('emits merged options when a control changes', async () => {
        const wrapper = mountForm({ options: { mode: 'url' } });
        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(wrapper.emitted('update:options').at(-1)).toEqual([{ mode: 'url', upload: true }]);
    });

    it('routes sub-field rows through the nativeFields channel', async () => {
        const wrapper = mountForm();
        // The sub-field source-node control is a SearchableSelect now.
        const select = wrapper.findAllComponents(SearchableSelect).at(-1);
        select.vm.$emit('update:modelValue', 'images.0.alt');

        expect(wrapper.emitted('update:nativeFields').at(-1))
            .toEqual([{ alt: { node: 'images.0.alt' } }]);
        expect(wrapper.emitted('update:options')).toBeUndefined();
    });
});

// The Matrix strategy's schema shape: a plain blockType select plus one
// matrixFields node PER block type, each gated to the select via showIf —
// so the generic visibleNodes filter is what swaps the cards.
const matrixSchema = [
    { type: 'select', handle: 'blockType', label: 'Block type', default: 'quote', options: [
        { value: 'quote', label: 'Quote' },
        { value: 'stat', label: 'Stat' },
    ] },
    { type: 'matrixFields', handle: 'fields', label: 'Block fields', blockType: 'quote',
        showIf: [{ handle: 'blockType', equals: 'quote' }],
        subFields: [{ type: 'text', handle: 'quote', label: 'Quote (quote)' }] },
    { type: 'matrixFields', handle: 'fields', label: 'Block fields', blockType: 'stat',
        showIf: [{ handle: 'blockType', equals: 'stat' }],
        subFields: [{ type: 'text', handle: 'number', label: 'Number (number)' }] },
];

const mountMatrixForm = (props = {}) => mount(SchemaForm, {
    props: {
        schema: matrixSchema,
        options: {},
        fields: {},
        nodeOptions: [{ value: 'quotes.text', label: 'quotes.text' }],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

describe('SchemaForm matrixFields', () => {
    it('routes matrixFields nodes to MatrixFields, showing only the default block type\'s card', () => {
        const wrapper = mountMatrixForm();

        // The blockType select's display-only default gates the cards
        // before the user touches anything.
        const cards = wrapper.findAllComponents(MatrixFields);
        expect(cards).toHaveLength(1);
        expect(cards[0].props('node').blockType).toBe('quote');
        expect(cards[0].text()).toContain('Quote (quote)');
    });

    it('swaps the visible card when the blockType option changes', async () => {
        const wrapper = mountMatrixForm({ options: { blockType: 'stat' } });

        let cards = wrapper.findAllComponents(MatrixFields);
        expect(cards).toHaveLength(1);
        expect(cards[0].props('node').blockType).toBe('stat');

        await wrapper.setProps({ options: { blockType: 'quote' } });
        cards = wrapper.findAllComponents(MatrixFields);
        expect(cards).toHaveLength(1);
        expect(cards[0].props('node').blockType).toBe('quote');
    });

    it('routes child rows through the fields channel, not options/nativeFields', () => {
        const wrapper = mountMatrixForm();
        // The child row's source-node control is the last SearchableSelect
        // (the blockType select renders as one too).
        const select = wrapper.findAllComponents(SearchableSelect).at(-1);
        select.vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:fields').at(-1))
            .toEqual([{ quote: { node: 'quotes.text' } }]);
        expect(wrapper.emitted('update:options')).toBeUndefined();
        expect(wrapper.emitted('update:nativeFields')).toBeUndefined();
    });
});
