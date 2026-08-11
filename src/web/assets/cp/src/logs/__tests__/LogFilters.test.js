import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import LogFilters from '../LogFilters.vue';
import SearchableSelect from '../../components/SearchableSelect.vue';

const $t = (s) => s;

/**
 * Two filters is enough to pin the behaviour that matters: the hidden inputs a
 * GET submit sends, and when a submit happens at all.
 */
const baseConfig = (over = {}) => ({
    url: '/admin/influx/logs',
    clearLabel: 'Clear filters',
    filters: [
        {
            name: 'link',
            label: 'Filter by link',
            placeholder: 'All links',
            searchable: true,
            options: { units: 'Units', pages: 'Pages' },
            selected: [],
        },
        {
            name: 'result',
            label: 'Filter by result',
            placeholder: 'All results',
            searchable: false,
            options: { created: 'Created', updated: 'Updated' },
            selected: [],
        },
    ],
    ...over,
});

const mountFilters = (over = {}) => {
    const wrapper = mount(LogFilters, {
        props: { config: baseConfig(over) },
        global: { mocks: { $t } },
        attachTo: document.body,
    });

    // happy-dom's form.submit() would try to navigate; the assertions are about
    // what the form carries and whether it was sent, not about the page load.
    wrapper.vm.$refs.form.submit = vi.fn();

    return wrapper;
};

const select = (wrapper, idx) => wrapper.findAllComponents(SearchableSelect).at(idx);
const submitted = (wrapper) => wrapper.vm.$refs.form.submit.mock.calls.length;
const hidden = (wrapper) => wrapper.findAll('input[type="hidden"]').map(i => [i.attributes('name'), i.attributes('value')]);

describe('LogFilters', () => {
    it('renders one multi-select per declared filter, labelled and seeded', () => {
        const wrapper = mountFilters({
            filters: baseConfig().filters.map(f => (f.name === 'link' ? { ...f, selected: ['units'] } : f)),
        });
        const links = select(wrapper, 0);

        expect(wrapper.findAllComponents(SearchableSelect)).toHaveLength(2);
        expect(links.props('multiple')).toBe(true);
        expect(links.props('ariaLabel')).toBe('Filter by link');
        expect(links.props('placeholder')).toBe('All links');
        expect(links.props('modelValue')).toEqual(['units']);
        expect(links.props('searchable')).toBe(true);
        expect(select(wrapper, 1).props('searchable')).toBe(false);
    });

    it('hands the select pairs built from the value:label map, in the map order', () => {
        expect(select(mountFilters(), 0).props('options')).toEqual([
            { value: 'units', label: 'Units' },
            { value: 'pages', label: 'Pages' },
        ]);
    });

    it('sends one input per picked value, so the filter arrives as a list', async () => {
        const wrapper = mountFilters();

        select(wrapper, 0).vm.$emit('update:modelValue', ['units', 'pages']);
        await wrapper.vm.$nextTick();

        expect(hidden(wrapper)).toEqual([['link[]', 'units'], ['link[]', 'pages']]);
    });

    it('applies on close rather than on each pick — the set is built first', async () => {
        const wrapper = mountFilters();
        const links = select(wrapper, 0);

        links.vm.$emit('update:modelValue', ['units']);
        links.vm.$emit('update:modelValue', ['units', 'pages']);
        await wrapper.vm.$nextTick();

        expect(submitted(wrapper)).toBe(0);

        links.vm.$emit('close');
        await wrapper.vm.$nextTick();

        expect(submitted(wrapper)).toBe(1);
    });

    it('does not apply when the menu closes on the selection it opened with', async () => {
        const wrapper = mountFilters();

        select(wrapper, 0).vm.$emit('close');
        await wrapper.vm.$nextTick();

        expect(submitted(wrapper)).toBe(0);
    });

    it('reads a re-ordered pick of the same values as no change', async () => {
        const wrapper = mountFilters({
            filters: baseConfig().filters.map(f => (f.name === 'link' ? { ...f, selected: ['units', 'pages'] } : f)),
        });
        const links = select(wrapper, 0);

        links.vm.$emit('update:modelValue', ['pages', 'units']);
        links.vm.$emit('close');
        await wrapper.vm.$nextTick();

        expect(submitted(wrapper)).toBe(0);
    });

    it('reads the last value being taken back out as "all", not as a value', async () => {
        const wrapper = mountFilters({
            filters: baseConfig().filters.map(f => (f.name === 'link' ? { ...f, selected: ['units'] } : f)),
        });
        const links = select(wrapper, 0);

        // Multi-select answers null once its set empties.
        links.vm.$emit('update:modelValue', null);
        links.vm.$emit('close');
        await wrapper.vm.$nextTick();

        expect(hidden(wrapper)).toEqual([]);
        expect(submitted(wrapper)).toBe(1);
    });

    it('offers the clear button only while something is filtered, and empties every filter', async () => {
        const wrapper = mountFilters();

        expect(wrapper.find('.influx-logs-filters-clear').exists()).toBe(false);

        select(wrapper, 0).vm.$emit('update:modelValue', ['units']);
        select(wrapper, 1).vm.$emit('update:modelValue', ['updated']);
        await wrapper.vm.$nextTick();

        const clear = wrapper.find('.influx-logs-filters-clear');

        expect(clear.text()).toBe('Clear filters');

        await clear.trigger('click');
        await wrapper.vm.$nextTick();

        expect(hidden(wrapper)).toEqual([]);
        expect(submitted(wrapper)).toBe(1);
    });

    it('submits to the overview URL, which carries no page — a filter change lands on page one', () => {
        expect(mountFilters().find('form').attributes('action')).toBe('/admin/influx/logs');
    });
});
