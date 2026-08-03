import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ElementSubFields from '../ElementSubFields.vue';
import SearchableSelect from '../../../SearchableSelect.vue';

/**
 * The card that renders a related element's sub-fields over BOTH mapping
 * channels. Two contracts to hold:
 *
 *   - the PRESERVING row rewrites it shares with every other SubFieldRows
 *     consumer: only node/default/useDefault are rewritten, a row's unknown
 *     keys survive untouched, and a row drops only when nothing is left on it;
 *   - the channel split: a row goes where its schema node says, a native row
 *     (no `channel` key) goes to `nativeFields`, and BOTH channels are written
 *     on every edit so a handle can't be left behind in its old home.
 */

const node = {
    type: 'elementSubFields',
    handle: 'nativeFields',
    label: 'Sub-fields',
    subFields: [
        { type: 'text', handle: 'alt', label: 'Alt text' },
        { type: 'text', handle: 'title', label: 'Title' },
        { type: 'text', handle: 'caption', label: 'Caption', channel: 'fields' },
    ],
};

const mountFields = (props = {}) => mount(ElementSubFields, {
    props: {
        node,
        fields: {},
        nativeFields: {},
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
const lastWrite = (wrapper, channel) => wrapper.emitted(channel).at(-1)[0];

describe('ElementSubFields rows', () => {
    it('writes a picked node under the flat rows map, dropping emptied rows', () => {
        const wrapper = mountFields({
            nativeFields: { alt: { node: 'images.0.alt' }, title: { node: 'images.0.name' } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({ title: { node: 'images.0.name' } });
    });

    it('keeps a row\'s unknown keys intact across a write round-trip', async () => {
        const wrapper = mountFields({
            nativeFields: { alt: { node: 'images.0.alt', options: { format: 'raw' } } },
        });

        // Rewriting the row's default must not disturb the unknown keys.
        await wrapper.findAll('input[type="text"]').at(0).setValue('Fallback');

        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({
            alt: { node: 'images.0.alt', default: 'Fallback', options: { format: 'raw' } },
        });
    });

    it('keeps a row alive on unknown keys alone when node/default clear out', () => {
        const wrapper = mountFields({
            nativeFields: { alt: { node: 'images.0.alt', options: { format: 'raw' } } },
        });
        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', '');

        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({ alt: { options: { format: 'raw' } } });
    });
});

describe('ElementSubFields channels', () => {
    it('renders both channels as one table, in schema order', () => {
        const wrapper = mountFields({
            fields: { caption: { node: 'images.0.caption' } },
            nativeFields: { alt: { node: 'images.0.alt' } },
        });

        expect(wrapper.findAll('.sub-field-row')).toHaveLength(3);
        // Row 3 is the `fields`-channel one and hydrates from that prop.
        expect(nodeSelect(wrapper, 2).props('modelValue')).toBe('images.0.caption');
        expect(nodeSelect(wrapper, 0).props('modelValue')).toBe('images.0.alt');
    });

    it('routes a row by its schema node\'s channel, keyless meaning nativeFields', () => {
        const wrapper = mountFields();

        nodeSelect(wrapper, 2).vm.$emit('update:modelValue', 'images.0.caption');

        expect(lastWrite(wrapper, 'update:fields')).toEqual({ caption: { node: 'images.0.caption' } });
        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({});

        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'images.0.alt');

        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({ alt: { node: 'images.0.alt' } });
    });

    it('writes both channels on every edit, so neither keeps a stale copy', () => {
        const wrapper = mountFields({ fields: { caption: { node: 'images.0.caption' } } });

        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'images.0.alt');

        // The untouched channel is re-emitted as it was rather than left unsaid.
        expect(wrapper.emitted('update:fields')).toHaveLength(1);
        expect(lastWrite(wrapper, 'update:fields')).toEqual({ caption: { node: 'images.0.caption' } });
    });

    it('round-trips a saved handle the card no longer offers, in its own channel', () => {
        // An Alt row saved before the volume dropped its Alt field: it renders
        // nowhere, but an edit elsewhere must not move or lose it.
        const wrapper = mountFields({
            node: { ...node, subFields: [node.subFields[2]] },
            fields: {},
            nativeFields: { alt: { node: 'images.0.alt' } },
        });

        nodeSelect(wrapper, 0).vm.$emit('update:modelValue', 'images.0.caption');

        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({ alt: { node: 'images.0.alt' } });
        expect(lastWrite(wrapper, 'update:fields')).toEqual({ caption: { node: 'images.0.caption' } });
    });

    it('empties BOTH channels on the card\'s Clear', async () => {
        const wrapper = mountFields({
            fields: { caption: { node: 'images.0.caption' } },
            nativeFields: { alt: { node: 'images.0.alt' } },
        });
        await wrapper.find('.clear-rows').trigger('click');

        expect(lastWrite(wrapper, 'update:fields')).toEqual({});
        expect(lastWrite(wrapper, 'update:nativeFields')).toEqual({});
    });
});
