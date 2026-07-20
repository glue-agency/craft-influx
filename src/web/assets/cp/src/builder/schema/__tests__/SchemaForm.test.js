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

// The Matrix strategy's schema shape: one matrixFields node PER block type
// (labeled with the type's name, Feed Me-style) — every card renders at
// once and reads/writes its own slice of the mapping's `blocks` channel.
// There is no gating select and no leading note.
const matrixSchema = [
    { type: 'matrixFields', handle: 'blocks', label: 'Quote', blockType: 'quote',
        subFields: [{ type: 'text', handle: 'quote', label: 'Quote' }] },
    { type: 'matrixFields', handle: 'blocks', label: 'Stat', blockType: 'stat',
        subFields: [{ type: 'text', handle: 'number', label: 'Number' }] },
];

const mountMatrixForm = (props = {}) => mount(SchemaForm, {
    props: {
        schema: matrixSchema,
        options: {},
        blocks: {},
        nodeOptions: [{ value: 'quotes.text', label: 'quotes.text' }],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

describe('SchemaForm matrixFields', () => {
    it('renders every block type\'s card at once, in schema order', () => {
        const wrapper = mountMatrixForm();

        const cards = wrapper.findAllComponents(MatrixFields);
        expect(cards).toHaveLength(2);
        expect(cards[0].props('node').blockType).toBe('quote');
        expect(cards[1].props('node').blockType).toBe('stat');
        expect(cards[0].text()).toContain('Quote');
        expect(cards[1].text()).toContain('Number');
    });

    it('never showIf-gates matrixFields nodes', () => {
        // Even a failing showIf leaves the card rendered — visibility gating
        // only applies to the other node types.
        const gated = matrixSchema.map((node) => (node.type === 'matrixFields'
            ? { ...node, showIf: [{ handle: 'blockType', equals: 'nope' }] }
            : node));
        const wrapper = mountMatrixForm({ schema: gated });

        expect(wrapper.findAllComponents(MatrixFields)).toHaveLength(2);
    });

    it('routes child rows through the blocks channel, preserving other types\' slices', () => {
        const wrapper = mountMatrixForm({
            blocks: { stat: { fields: { number: { node: 'stats.value' } } } },
        });
        // The quote card's child source-node control is the first
        // SearchableSelect (cards render in schema order).
        const select = wrapper.findAllComponents(SearchableSelect).at(0);
        select.vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:blocks').at(-1)).toEqual([{
            stat:  { fields: { number: { node: 'stats.value' } } },
            quote: { fields: { quote: { node: 'quotes.text' } } },
        }]);
        expect(wrapper.emitted('update:options')).toBeUndefined();
        expect(wrapper.emitted('update:nativeFields')).toBeUndefined();
    });
});
