import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ElementSubFields from '../ElementSubFields.vue';
import SearchableSelect from '../../../SearchableSelect.vue';

/**
 * Locks in the nativeFields-channel writer contract — specifically that it
 * shares SubFieldRows' PRESERVING row rewrites with the blocks channel:
 * only node/default/useDefault are rewritten, a row's unknown keys survive
 * untouched, and a row drops only when nothing at all is left on it.
 * (The channel's own rows never carry unknown keys today; the specs pin
 * the unified writer so the two channels can't drift apart again.)
 */

const node = {
    type: 'elementSubFields',
    handle: 'nativeFields',
    label: 'Asset sub-fields',
    subFields: [
        { type: 'text', handle: 'alt', label: 'Alt text' },
        { type: 'text', handle: 'title', label: 'Title' },
    ],
};

const mountFields = (props = {}) => mount(ElementSubFields, {
    props: {
        node,
        modelValue: {},
        nodeOptions: [
            { value: 'images.0.alt', label: 'images.0.alt' },
            { value: 'images.0.name', label: 'images.0.name' },
        ],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

// Row i's source-node control is the i-th SearchableSelect.
const nodeSelect = (wrapper, idx) => wrapper.findAllComponents(SearchableSelect).at(idx);

describe('ElementSubFields', () => {
    it('writes a picked node under the flat rows map, dropping emptied rows', () => {
        const wrapper = mountFields({
            modelValue: { alt: { node: 'images.0.alt' }, title: { node: 'images.0.name' } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ title: { node: 'images.0.name' } }]);
    });

    it('keeps a row\'s unknown keys intact across a write round-trip', async () => {
        const wrapper = mountFields({
            modelValue: { alt: { node: 'images.0.alt', options: { format: 'raw' } } },
        });

        // Rewriting the row's default must not disturb the unknown keys.
        await wrapper.findAll('input[type="text"]').at(0).setValue('Fallback');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            alt: { node: 'images.0.alt', default: 'Fallback', options: { format: 'raw' } },
        }]);
    });

    it('keeps a row alive on unknown keys alone when node/default clear out', () => {
        const wrapper = mountFields({
            modelValue: { alt: { node: 'images.0.alt', options: { format: 'raw' } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ alt: { options: { format: 'raw' } } }]);
    });
});
