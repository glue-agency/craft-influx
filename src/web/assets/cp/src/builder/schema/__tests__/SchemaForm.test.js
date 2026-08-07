import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SchemaForm from '../SchemaForm.vue';

/**
 * The stacked form an auth strategy's schema renders as: Craft `.field` blocks over
 * a flat `options` object. Dispatch is by node type through the shared control
 * registry; what this locks in is the layout's own contract — the heading and
 * instructions chrome, the plain CP select, display-only defaults, showIf
 * visibility, and merged emits.
 *
 * The mapping extras are a different renderer over the same registry — see
 * MappingExtras.test.js.
 */

const authSchema = [
    { type: 'select', handle: 'placement', label: 'Send as', default: 'header', options: [
        { value: 'header', label: 'Header' },
        { value: 'query', label: 'Query parameter' },
    ] },
    { type: 'code', handle: 'name', label: 'Header name', showIf: [{ handle: 'placement', equals: 'header' }] },
    { type: 'tokenInput', handle: 'token', label: 'Token' },
    { type: 'lightswitch', handle: 'preflight', label: 'Preflight', showIf: [{ handle: 'name' }] },
];

// The one schema shape with a cascade: a target's criteria form, where the
// second dropdown's list is keyed on the first's value.
const cascadeSchema = [
    { type: 'select', handle: 'section', label: 'Section', options: [
        { value: 'news', label: 'News' },
        { value: 'movies', label: 'Movies' },
    ] },
    { type: 'select', handle: 'type', label: 'Entry Type', dependsOn: 'section', optionsBy: {
        news: [{ value: 'article', label: 'Article' }],
        movies: [{ value: 'feature', label: 'Feature' }, { value: 'short', label: 'Short' }],
    } },
];

const mountForm = (props = {}) => mount(SchemaForm, {
    props: { schema: authSchema, options: {}, ...props },
    global: { mocks: { $t: (s) => s } },
});

describe('SchemaForm', () => {
    it('gives every node a Craft field block with its own heading', () => {
        const wrapper = mountForm({ schema: [authSchema[0]] });

        expect(wrapper.find('.influx-schema-form').classes()).toContain('is-stacked');
        expect(wrapper.find('.field .heading label').text()).toBe('Send as');
    });

    it('renders a flat select as the plain CP control', () => {
        // The native select IS the idiom in a stacked field — the searchable one
        // belongs to the mapping grid.
        const wrapper = mountForm();

        expect(wrapper.find('.select select').exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'SearchableSelect' }).exists()).toBe(false);
    });

    it('upgrades a GROUPED select, whose headings the plain one can’t render', () => {
        const wrapper = mountForm({
            schema: [{ type: 'select', handle: 'match', label: 'Match by', options: [
                { label: 'Entry', options: [{ value: 'id', label: 'ID' }] },
            ] }],
        });

        expect(wrapper.findComponent({ name: 'SearchableSelect' }).exists()).toBe(true);
    });

    it('applies a declared default for display without emitting it', () => {
        const wrapper = mountForm();

        expect(wrapper.find('.select select').element.value).toBe('header');
        expect(wrapper.emitted('update:options')).toBeUndefined();
    });

    it('renders code nodes as monospace text inputs', () => {
        const wrapper = mountForm({ schema: [{ type: 'code', handle: 'name', label: 'Header name' }] });

        expect(wrapper.find('input[type="text"]').classes()).toContain('code');
    });

    it('mounts a tokenInput as the token picker', () => {
        expect(mountForm().findComponent({ name: 'TokenizedInput' }).exists()).toBe(true);
    });

    it('hides nodes whose showIf conditions fail — including chained ones', async () => {
        const wrapper = mountForm({ options: { placement: 'query' } });

        expect(wrapper.find('input[type="text"].code').exists()).toBe(false);
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);

        await wrapper.setProps({ options: { placement: 'header' } });
        expect(wrapper.find('input[type="text"].code').exists()).toBe(true);
        // preflight needs a truthy `name`, which nothing has typed yet.
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);

        await wrapper.setProps({ options: { placement: 'header', name: 'X-Api-Key' } });
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true);
    });

    it('emits merged options when a control changes', async () => {
        const wrapper = mountForm({ options: { placement: 'header', name: 'X-Api-Key' } });

        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(wrapper.emitted('update:options').at(-1))
            .toEqual([{ placement: 'header', name: 'X-Api-Key', preflight: true }]);
    });

    it('keys a dependent select’s options on its parent’s value', async () => {
        // The element-target criteria cascade: entry types belong to a section, so
        // the list can't be one flat array.
        const wrapper = mountForm({ schema: cascadeSchema, options: {} });

        const options = () => wrapper.findAll('.field').at(1).findAll('option').map((o) => o.attributes('value'));

        // Nothing picked yet — no list applies.
        expect(options()).toEqual([]);

        await wrapper.setProps({ options: { section: 'news' } });
        expect(options()).toEqual(['article']);

        await wrapper.setProps({ options: { section: 'movies' } });
        expect(options()).toEqual(['feature', 'short']);
    });

    it('clears a dependent when its parent changes, since the old pick left the list', async () => {
        const wrapper = mountForm({
            schema: cascadeSchema,
            options: { section: 'news', type: 'article' },
        });

        await wrapper.findAll('.select select').at(0).setValue('movies');

        expect(wrapper.emitted('update:options').at(-1)).toEqual([{ section: 'movies', type: null }]);
    });

    it('leaves a node without optionsBy alone', async () => {
        // Both keys are inert on the auth forms and mapping extras.
        const wrapper = mountForm({ options: { placement: 'header' } });

        await wrapper.findAll('.select select').at(0).setValue('query');

        expect(wrapper.emitted('update:options').at(-1)).toEqual([{ placement: 'query' }]);
    });

    it('renders an unknown node type as a labeled text input', async () => {
        // A third-party kind (SchemaBuilder::node()) must not vanish: the registry
        // falls through to the text control, so it stays labelled and keeps
        // reading/writing its own handle.
        const wrapper = mountForm({
            schema: [{ type: 'colorPicker', handle: 'accent', label: 'Accent', default: '#f00' }],
        });

        expect(wrapper.find('.field .heading label').text()).toBe('Accent');

        const input = wrapper.find('input[type="text"]');
        expect(input.element.value).toBe('#f00');
        expect(wrapper.emitted('update:options')).toBeUndefined();

        await input.setValue('#0f0');
        expect(wrapper.emitted('update:options').at(-1)).toEqual([{ accent: '#0f0' }]);
    });
});
