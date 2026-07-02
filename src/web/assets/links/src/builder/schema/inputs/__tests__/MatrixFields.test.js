import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MatrixFields from '../MatrixFields.vue';
import SearchableSelect from '../../../SearchableSelect.vue';

/**
 * Locks in the blocks-channel writer contract: the card owns ONE block
 * type's slice of the mapping's whole `blocks` object. Child rows land
 * under `blocks[type].fields[handle]` with the same node/default/useDefault
 * vocabulary as the parent rows; other types' slices, unknown keys on the
 * card's own type entry, and a child's unknown keys (per-type `options`,
 * nested `fields`, …) survive every rewrite untouched; emptied slices
 * collapse away (row → `fields` map → type entry).
 */

const node = {
    type: 'matrixFields',
    handle: 'blocks',
    label: 'Quote',
    blockType: 'quote',
    subFields: [
        { type: 'text', handle: 'quote', label: 'Quote (quote)' },
        { type: 'text', handle: 'cite', label: 'Cite (cite)' },
    ],
};

const mountFields = (props = {}) => mount(MatrixFields, {
    props: {
        node,
        modelValue: {},
        nodeOptions: [
            { value: 'quotes.text', label: 'quotes.text' },
            { value: 'quotes.author', label: 'quotes.author' },
        ],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

// Row i's source-node control is the i-th SearchableSelect.
const nodeSelect = (wrapper, idx) => wrapper.findAllComponents(SearchableSelect).at(idx);

describe('MatrixFields', () => {
    it('writes a picked node under blocks[type].fields[handle].node', () => {
        const wrapper = mountFields();
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { node: 'quotes.text' } } } }]);
    });

    it('leaves other block types\' slices untouched on every write', () => {
        const wrapper = mountFields({
            modelValue: { stat: { fields: { number: { node: 'stats.value' } } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            stat:  { fields: { number: { node: 'stats.value' } } },
            quote: { fields: { quote: { node: 'quotes.text' } } },
        }]);
    });

    it('preserves unknown keys on its own type\'s entry (nativeFields, …)', () => {
        const wrapper = mountFields({
            modelValue: { quote: { nativeFields: { title: { node: 'quotes.author' } } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                nativeFields: { title: { node: 'quotes.author' } },
                fields: { quote: { node: 'quotes.text' } },
            },
        }]);
    });

    it('drops a child handle when its last slot empties', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text' }, cite: { node: 'quotes.author' } } },
            },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { cite: { node: 'quotes.author' } } } }]);
    });

    it('collapses the type out of blocks when its last child clears', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text' } } },
                stat:  { fields: { number: { node: 'stats.value' } } },
            },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ stat: { fields: { number: { node: 'stats.value' } } } }]);

        // …and to a bare {} when no other type remains — MappingRow's
        // writeMapping() then prunes the empty `blocks` off the mapping.
        const last = mountFields({
            modelValue: { quote: { fields: { quote: { node: 'quotes.text' } } } },
        });
        nodeSelect(last, 0).vm.$emit('update:modelValue', '');

        expect(last.emitted('update:modelValue').at(-1)).toEqual([{}]);
    });

    it('keeps a type entry alive on unknown keys alone when fields empties', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: {
                    fields: { quote: { node: 'quotes.text' } },
                    nativeFields: { title: { node: 'quotes.author' } },
                },
            },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { nativeFields: { title: { node: 'quotes.author' } } } }]);
    });

    it('round-trips the __default__ sentinel to useDefault, never the wire node', () => {
        const wrapper = mountFields({
            modelValue: { quote: { fields: { quote: { node: 'quotes.text' } } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '__default__');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { useDefault: true } } } }]);

        // And the saved flag renders back as the sentinel.
        const hydrated = mountFields({
            modelValue: { quote: { fields: { quote: { useDefault: true } } } },
        });
        expect(nodeSelect(hydrated, 0).props('modelValue')).toBe('__default__');
    });

    it('keeps a child\'s unknown keys intact across a write round-trip', async () => {
        const saved = {
            quote: {
                fields: {
                    quote: {
                        node: 'quotes.text',
                        options: { format: 'raw' },
                        fields: { nested: { node: 'quotes.meta.id' } },
                    },
                },
            },
        };
        const wrapper = mountFields({ modelValue: saved });

        // Rewriting the row's default must not disturb options / fields.
        await wrapper.findAll('input[type="text"]').at(0).setValue('Fallback');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                fields: {
                    quote: {
                        node: 'quotes.text',
                        default: 'Fallback',
                        options: { format: 'raw' },
                        fields: { nested: { node: 'quotes.meta.id' } },
                    },
                },
            },
        }]);
    });

    it('keeps a row alive on unknown keys alone when node/default clear out', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text', options: { format: 'raw' } } } },
            },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { options: { format: 'raw' } } } } }]);
    });

    it('flags saved nodes missing from the fetched sample — never without a sample', () => {
        const saved = { quote: { fields: { quote: { node: 'gone.node' } } } };

        // No sample fetched (null) → can't know → nothing is missing.
        const unfetched = mountFields({ modelValue: saved, discoveredNodes: null });
        expect(unfetched.find('.influx-missing-badge').exists()).toBe(false);

        const fetched = mountFields({
            modelValue: saved,
            discoveredNodes: [{ value: 'quotes.text', label: 'quotes.text' }],
        });
        expect(fetched.find('.influx-missing-badge').exists()).toBe(true);
        expect(fetched.find('.pill-missing').text()).toContain('1');
    });

    it('renders an empty-state hint — not the column headings — for a fieldless block type', () => {
        const wrapper = mountFields({ node: { ...node, subFields: [] } });

        expect(wrapper.text()).toContain('This block type has no mappable sub-fields.');
        expect(wrapper.find('.influx-mapping-headings').exists()).toBe(false);
        expect(wrapper.find('.sub-field-row').exists()).toBe(false);
        expect(wrapper.find('.pill-count').text()).toBe('0');
    });
});
