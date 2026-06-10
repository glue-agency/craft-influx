import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SchemaForm from '../SchemaForm.vue';

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
        nodeOptions: [{ value: 'images.0.alt', label: 'images → 0 → alt' }],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

describe('SchemaForm', () => {
    it('renders by node type and applies display-only defaults', () => {
        const wrapper = mountForm();
        expect(wrapper.find('select').element.value).toBe('id');
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
        const select = wrapper.findAll('select').at(-1);
        await select.setValue('images.0.alt');

        expect(wrapper.emitted('update:nativeFields').at(-1))
            .toEqual([{ alt: { node: 'images.0.alt' } }]);
        expect(wrapper.emitted('update:options')).toBeUndefined();
    });
});
