import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MappingExtras from '../MappingExtras.vue';
import MatrixFields from '../inputs/MatrixFields.vue';
import SubFieldRows from '../inputs/SubFieldRows.vue';
import SearchableSelect from '../../SearchableSelect.vue';

/**
 * The `extra` region's renderer, and with it the two binding arities that are the
 * only thing it knows about a node beyond its type: a LEAF writes one key of the
 * mapping's `options`, a CONTAINER binds whole stored channels and emits them back.
 *
 * Everything is asserted through the one `update:mapping` payload, because that is
 * what the owner receives — a card can't half-apply a write that spans two channels.
 * Working on one mapping is also what lets a NESTED sub-field row mount this same
 * renderer for its own extras.
 */

// The Assets strategy's extras: a gating select, two showIf-chained controls, and
// one sub-field card spanning both channels.
const assetSchema = [
    { type: 'select', handle: 'mode', label: 'Match by', default: 'id', options: [
        { value: 'id', label: 'Asset ID' },
        { value: 'url', label: 'URL' },
    ] },
    { type: 'lightswitch', handle: 'upload', label: 'Download missing', showIf: [{ handle: 'mode', equals: 'url' }] },
    { type: 'text', handle: 'volume', label: 'Target volume', showIf: [
        { handle: 'mode', equals: 'url' },
        { handle: 'upload' },
    ] },
    { type: 'elementSubFields', handle: 'nativeFields', label: 'Sub-fields', subFields: [
        { type: 'text', handle: 'alt', label: 'Alt text' },
        { type: 'text', handle: 'caption', label: 'Caption', channel: 'fields' },
    ] },
];

const mountExtras = (props = {}) => mount(MappingExtras, {
    props: {
        nodes: assetSchema,
        mapping: {},
        nodeOptions: [{ value: 'images.0.alt', label: 'images.0.alt' }],
        ...props,
    },
    // Mirrors installT's Craft-less fallback, placeholders included — a label
    // that interpolates a handle is only worth asserting once it's substituted.
    global: { mocks: { $t: (s, params) => (params ? s.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) : s) } },
});

const written = (wrapper) => wrapper.emitted('update:mapping').at(-1)[0];

describe('MappingExtras leaves', () => {
    it('renders by node type and applies display-only defaults', () => {
        const wrapper = mountExtras();
        const select = wrapper.findComponent(SearchableSelect);

        expect(select.props('modelValue')).toBe('id');
        expect(select.find('.influx-searchable-select-trigger .value').text()).toBe('Asset ID');
        // An untouched default must never be written into the saved options.
        expect(wrapper.emitted('update:mapping')).toBeUndefined();
    });

    it('labels each control and keeps them in the options card', () => {
        const wrapper = mountExtras({ nodes: [assetSchema[0]] });

        expect(wrapper.find('.extras-options .option label').text()).toBe('Match by');
    });

    it('gives a lightswitch its label inline, with no option wrapper', () => {
        const wrapper = mountExtras({ nodes: [assetSchema[1]], mapping: { options: { mode: 'url' } } });

        expect(wrapper.find('.extras-options .option').exists()).toBe(false);
        expect(wrapper.find('label.inline-toggle').text()).toContain('Download missing');
    });

    it('writes one key of the options channel, merging the rest', () => {
        const wrapper = mountExtras({ mapping: { node: 'images', options: { mode: 'url' } } });

        wrapper.find('input[type="checkbox"]').setValue(true);

        expect(written(wrapper)).toEqual({ node: 'images', options: { mode: 'url', upload: true } });
    });

    it('drops an options key rather than storing its empty', () => {
        const wrapper = mountExtras({ mapping: { node: 'images', options: { mode: 'url', upload: true } } });

        wrapper.find('input[type="checkbox"]').setValue(false);

        expect(written(wrapper)).toEqual({ node: 'images', options: { mode: 'url' } });
    });

    it('hides nodes whose showIf conditions fail — including chained ones', async () => {
        const wrapper = mountExtras();
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);

        await wrapper.setProps({ mapping: { options: { mode: 'url' } } });
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true);
        // `volume` needs mode=url AND upload truthy, so what's left is the two
        // sub-field rows' own default editors.
        expect(wrapper.findAll('input[type="text"].text')).toHaveLength(2);

        await wrapper.setProps({ mapping: { options: { mode: 'url', upload: true } } });
        expect(wrapper.findAll('input[type="text"].text')).toHaveLength(3);
    });

    it('renders an unknown node type as a labeled text input', async () => {
        // A third-party kind (SchemaBuilder::node()) must not vanish: the registry
        // falls through to the text control, so it stays labelled and keeps
        // reading/writing its own handle.
        const wrapper = mountExtras({
            nodes: [{ type: 'colorPicker', handle: 'accent', label: 'Accent', default: '#f00' }],
        });

        expect(wrapper.find('.option label').text()).toBe('Accent');

        const input = wrapper.find('input[type="text"]');
        expect(input.element.value).toBe('#f00');
        expect(wrapper.emitted('update:mapping')).toBeUndefined();

        await input.setValue('#0f0');
        expect(written(wrapper)).toEqual({ options: { accent: '#0f0' } });
    });
});

describe('MappingExtras element sub-fields', () => {
    it('renders one card for both channels', () => {
        // The merged card is a single SubFieldRows table, not one per channel — and
        // it stays out of the options fieldset.
        const wrapper = mountExtras();

        expect(wrapper.findAll('.influx-mapping-group')).toHaveLength(1);
    });

    it('routes a keyless row through the nativeFields channel', () => {
        const wrapper = mountExtras();

        wrapper.findAllComponents(SearchableSelect).at(-2).vm.$emit('update:modelValue', 'images.0.alt');

        // Both channels are written every time — the companion is the untouched
        // one, empty here, which is why an emptied channel prunes away.
        expect(written(wrapper)).toEqual({ nativeFields: { alt: { node: 'images.0.alt' } } });
    });

    it('routes a channel-carrying row of the SAME card through the fields channel', () => {
        const wrapper = mountExtras();

        wrapper.findAllComponents(SearchableSelect).at(-1).vm.$emit('update:modelValue', 'images.0.caption');

        expect(written(wrapper)).toEqual({ fields: { caption: { node: 'images.0.caption' } } });
    });

    it('leaves the options channel alone when a card writes', () => {
        const wrapper = mountExtras({ mapping: { options: { mode: 'url' } } });

        wrapper.findAllComponents(SearchableSelect).at(-1).vm.$emit('update:modelValue', 'images.0.caption');

        expect(written(wrapper).options).toEqual({ mode: 'url' });
    });
});

// The Table strategy's schema shape: ONE subFields node holding a row per column,
// keyed by column id — the card writes the mapping's flat `fields` channel, the one
// a relation's sub-fields also live in.
const tableSchema = [
    { type: 'subFields', handle: 'fields', label: 'Columns', subFields: [
        { type: 'text', handle: 'col1', label: 'Label' },
        { type: 'text', handle: 'col2', label: 'Value' },
    ] },
];

const mountTable = (props = {}) => mountExtras({
    nodes: tableSchema,
    nodeOptions: [{ value: 'specs.label', label: 'specs.label' }],
    ...props,
});

describe('MappingExtras own sub-fields', () => {
    it('renders the sub-field table with a row per column', () => {
        const wrapper = mountTable();
        const cards = wrapper.findAllComponents(SubFieldRows);

        expect(cards).toHaveLength(1);
        expect(cards[0].props('node').label).toBe('Columns');
        expect(wrapper.findAll('.sub-field-row')).toHaveLength(2);
        // A card is not an options control — it must stay out of the fieldset.
        expect(wrapper.find('.extras-options').exists()).toBe(false);
    });

    it('routes column rows through the fields channel, preserving the other columns', () => {
        const wrapper = mountTable({
            mapping: { fields: { col2: { node: 'specs.value' } } },
        });

        wrapper.findAllComponents(SearchableSelect).at(0).vm.$emit('update:modelValue', 'specs.label');

        expect(written(wrapper)).toEqual({
            fields: {
                col2: { node: 'specs.value' },
                col1: { node: 'specs.label' },
            },
        });
    });
});

// The Matrix strategy's schema shape: one matrixFields node PER block type (labeled
// with the type's name, Feed Me-style) — every card renders at once and reads/writes
// its own slice of the mapping's `blocks` channel.
const matrixSchema = [
    { type: 'matrixFields', handle: 'blocks', label: 'Quote', blockType: 'quote',
        subFields: [{ type: 'text', handle: 'quote', label: 'Quote' }] },
    { type: 'matrixFields', handle: 'blocks', label: 'Stat', blockType: 'stat',
        subFields: [{ type: 'text', handle: 'number', label: 'Number' }] },
];

const mountMatrix = (props = {}) => mountExtras({
    nodes: matrixSchema,
    nodeOptions: [{ value: 'quotes.text', label: 'quotes.text' }],
    ...props,
});

describe('MappingExtras matrix blocks', () => {
    it('renders every block type\'s card at once, in schema order', () => {
        const cards = mountMatrix().findAllComponents(MatrixFields);

        expect(cards).toHaveLength(2);
        expect(cards[0].props('node').blockType).toBe('quote');
        expect(cards[1].props('node').blockType).toBe('stat');
        expect(cards[0].text()).toContain('Quote');
        expect(cards[1].text()).toContain('Number');
    });

    /**
     * A Matrix card's sub-field paths are relative to one item of the list the
     * row names — which discovery, having only ever produced absolute paths,
     * would flag as missing on every row.
     */
    it('withholds the discovered nodes from a matrix card', () => {
        const wrapper = mountMatrix({
            mapping: { node: 'content' },
            discoveredNodes: [{ value: 'quotes.text', label: 'quotes.text' }],
        });

        expect(wrapper.findAllComponents(MatrixFields)[0].props('discoveredNodes')).toBeNull();
    });

    /**
     * A single-type list has one block type by definition, so once one card
     * carries rows the others close rather than letting a second type be mapped.
     */
    it('locks the other block types out under a single-type list', () => {
        const mapping = {
            node: 'content',
            options: { blockSource: 'listSingle' },
            blocks: { quote: { fields: { quote: { node: 'quote' } } } },
        };

        const cards = mountMatrix({ mapping }).findAllComponents(MatrixFields);

        expect(cards[0].props('node').blockType).toBe('quote');
        expect(cards[0].vm.lockedOut).toBe(false);
        expect(cards[1].vm.lockedOut).toBe(true);
        expect(cards[1].text()).toContain('quote');
    });

    /**
     * Switching an already-two-type mapping to a single-type list must not lock
     * both cards: "clear nodes" is itself gated on the card being editable, so
     * that would leave no way out but changing the setting back.
     */
    it('keeps a populated card open so a two-type conflict stays resolvable', () => {
        const mapping = {
            node: 'content',
            options: { blockSource: 'listSingle' },
            blocks: {
                quote: { fields: { quote: { node: 'quote' } } },
                stat: { fields: { number: { node: 'number' } } },
            },
        };

        const cards = mountMatrix({ mapping }).findAllComponents(MatrixFields);

        expect(cards.map((card) => card.vm.lockedOut)).toEqual([false, false]);
    });

    it('leaves every block type open under the keyed and noded lists', () => {
        const mapping = {
            node: 'content',
            options: { blockSource: 'listByKey' },
            blocks: { quote: { fields: { quote: { node: 'quote' } } } },
        };

        const cards = mountMatrix({ mapping }).findAllComponents(MatrixFields);

        expect(cards.map((card) => card.vm.lockedOut)).toEqual([false, false]);
    });

    it('never showIf-gates a matrix card', () => {
        // Even a failing showIf leaves the card rendered — gating only applies to
        // the leaf controls.
        const gated = matrixSchema.map((node) => ({ ...node, showIf: [{ handle: 'nope', equals: 'x' }] }));

        expect(mountMatrix({ nodes: gated }).findAllComponents(MatrixFields)).toHaveLength(2);
    });

    it('routes child rows through the blocks channel, preserving other types\' slices', () => {
        const wrapper = mountMatrix({
            mapping: { blocks: { stat: { fields: { number: { node: 'stats.value' } } } } },
        });

        // Cards render in schema order, so the quote card's child comes first.
        wrapper.findAllComponents(SearchableSelect).at(0).vm.$emit('update:modelValue', 'quotes.text');

        expect(written(wrapper)).toEqual({
            blocks: {
                stat:  { fields: { number: { node: 'stats.value' } } },
                quote: { fields: { quote: { node: 'quotes.text' } } },
            },
        });
    });
});
