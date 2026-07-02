import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MatrixFields from '../MatrixFields.vue';
import SearchableSelect from '../../../SearchableSelect.vue';

/**
 * Locks in the fields-channel writer contract: child rows land under
 * `fields[handle]` with the same node/default/useDefault vocabulary as the
 * parent rows, fully-empty rows collapse away, and a child's unknown keys
 * (per-type `options`, nested `fields`, …) survive every rewrite untouched.
 */

const node = {
    type: 'matrixFields',
    handle: 'fields',
    label: 'Block fields',
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
    it('writes a picked node under fields[handle].node', () => {
        const wrapper = mountFields();
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { node: 'quotes.text' } }]);
    });

    it('drops a child handle when its last slot empties', () => {
        const wrapper = mountFields({
            modelValue: { quote: { node: 'quotes.text' }, cite: { node: 'quotes.author' } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ cite: { node: 'quotes.author' } }]);
    });

    it('round-trips the __default__ sentinel to useDefault, never the wire node', () => {
        const wrapper = mountFields({ modelValue: { quote: { node: 'quotes.text' } } });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '__default__');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { useDefault: true } }]);

        // And the saved flag renders back as the sentinel.
        const hydrated = mountFields({ modelValue: { quote: { useDefault: true } } });
        expect(nodeSelect(hydrated, 0).props('modelValue')).toBe('__default__');
    });

    it('keeps a child\'s unknown keys intact across a write round-trip', async () => {
        const saved = {
            quote: {
                node: 'quotes.text',
                options: { format: 'raw' },
                fields: { nested: { node: 'quotes.meta.id' } },
            },
        };
        const wrapper = mountFields({ modelValue: saved });

        // Rewriting the row's default must not disturb options / fields.
        await wrapper.findAll('input[type="text"]').at(0).setValue('Fallback');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                node: 'quotes.text',
                default: 'Fallback',
                options: { format: 'raw' },
                fields: { nested: { node: 'quotes.meta.id' } },
            },
        }]);
    });

    it('keeps a row alive on unknown keys alone when node/default clear out', () => {
        const wrapper = mountFields({
            modelValue: { quote: { node: 'quotes.text', options: { format: 'raw' } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { options: { format: 'raw' } } }]);
    });

    it('flags saved nodes missing from the fetched sample — never without a sample', () => {
        const saved = { quote: { node: 'gone.node' } };

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
});
