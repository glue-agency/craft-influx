import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import SearchableSelect from '../SearchableSelect.vue';

/**
 * Characterization spec for SearchableSelect — pins the component's
 * observable behavior (open/close paths, keyboard nav, filtering, sentinel
 * handling, highlight markup, drop direction) so the composable extraction
 * can be verified against it. Every case here passed against the pre-
 * refactor Options-API component.
 */

const FLAT = [
    { value: '', label: '—' },
    { value: 'alpha', label: 'Alpha' },
    { value: 'beta', label: 'Beta' },
    { value: 'gamma', label: 'Gamma <b>raw</b>' },
];

const GROUPED = [
    { label: 'Element', kind: 'element', options: [
        { value: '{id}', label: '{id}' },
        { value: '{slug}', label: '{slug}' },
    ] },
    { label: 'Environment', kind: 'env', options: [
        { value: '$API_BASE', label: '$API_BASE' },
    ] },
];

const mountSelect = (props = {}, mountOptions = {}) => mount(SearchableSelect, {
    props: { options: FLAT, searchable: true, ...props },
    global: { mocks: { $t: (s) => s } },
    ...mountOptions,
});

const menu = (wrapper) => wrapper.find('.influx-searchable-select-menu');
const trigger = (wrapper) => wrapper.find('.influx-searchable-select-trigger');
const searchInput = (wrapper) => wrapper.find('.influx-searchable-select-search input');
const highlightedIdx = (wrapper) => wrapper.find('li.highlighted').attributes('data-flat-idx');

const open = async (wrapper) => {
    await trigger(wrapper).trigger('click');
    await nextTick();
};

describe('open / close', () => {
    it('is closed by default and opens on trigger click', async () => {
        const wrapper = mountSelect();
        expect(menu(wrapper).exists()).toBe(false);

        await open(wrapper);
        expect(menu(wrapper).exists()).toBe(true);
        expect(wrapper.classes()).toContain('open');
        expect(trigger(wrapper).attributes('aria-expanded')).toBe('true');
    });

    it.each(['Enter', ' ', 'ArrowDown', 'ArrowUp'])('opens via %j on the closed trigger', async (key) => {
        const wrapper = mountSelect();
        await trigger(wrapper).trigger('keydown', { key });
        expect(menu(wrapper).exists()).toBe(true);
    });

    it('ignores other keys on the closed trigger', async () => {
        const wrapper = mountSelect();
        await trigger(wrapper).trigger('keydown', { key: 'a' });
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('does not open when disabled', async () => {
        const wrapper = mountSelect({ disabled: true });
        await trigger(wrapper).trigger('click');
        await trigger(wrapper).trigger('keydown', { key: 'Enter' });
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('closes on Escape in the search input', async () => {
        const wrapper = mountSelect();
        await open(wrapper);

        await searchInput(wrapper).trigger('keydown', { key: 'Escape' });
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('closes on document-level Escape when no search box owns the keys', async () => {
        const wrapper = mountSelect({ searchable: false });
        await open(wrapper);

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await nextTick();
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('closes on mousedown outside, stays open on mousedown inside', async () => {
        const wrapper = mountSelect({}, { attachTo: document.body });
        await open(wrapper);

        searchInput(wrapper).element.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await nextTick();
        expect(menu(wrapper).exists()).toBe(true);

        document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await nextTick();
        expect(menu(wrapper).exists()).toBe(false);

        wrapper.unmount();
    });
});

describe('keyboard highlight + commit', () => {
    it('seeds the highlight on the selected option when opening', async () => {
        const wrapper = mountSelect({ modelValue: 'beta' });
        await open(wrapper);

        expect(highlightedIdx(wrapper)).toBe('2');
        expect(wrapper.find('li.selected').text()).toContain('Beta');
    });

    it('moves the highlight with the arrow keys, wrapping at both ends', async () => {
        const wrapper = mountSelect();
        await open(wrapper);
        expect(highlightedIdx(wrapper)).toBe('0');

        await searchInput(wrapper).trigger('keydown', { key: 'ArrowUp' });
        expect(highlightedIdx(wrapper)).toBe('3'); // wrapped to the end

        await searchInput(wrapper).trigger('keydown', { key: 'ArrowDown' });
        expect(highlightedIdx(wrapper)).toBe('0'); // wrapped back to the start

        await searchInput(wrapper).trigger('keydown', { key: 'ArrowDown' });
        expect(highlightedIdx(wrapper)).toBe('1');
    });

    it('commits the highlighted option on Enter and closes', async () => {
        const wrapper = mountSelect();
        await open(wrapper);

        await searchInput(wrapper).trigger('keydown', { key: 'ArrowDown' });
        await searchInput(wrapper).trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['alpha']);
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('commits on click and closes', async () => {
        const wrapper = mountSelect();
        await open(wrapper);

        await wrapper.find('li[data-flat-idx="2"]').trigger('click');
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['beta']);
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('lets the trigger own the menu keys when there is no search box', async () => {
        const wrapper = mountSelect({ searchable: false });
        await open(wrapper);

        await trigger(wrapper).trigger('keydown', { key: 'ArrowDown' });
        expect(highlightedIdx(wrapper)).toBe('1');

        await trigger(wrapper).trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['alpha']);
    });

    it('clears the value on Backspace in an empty search box', async () => {
        const wrapper = mountSelect({ modelValue: 'beta' });
        await open(wrapper);

        await searchInput(wrapper).trigger('keydown', { key: 'Backspace' });
        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual(['']);
        expect(menu(wrapper).exists()).toBe(false);
    });

    it('leaves Backspace alone while a query is being edited', async () => {
        const wrapper = mountSelect({ modelValue: 'beta' });
        await open(wrapper);

        await searchInput(wrapper).setValue('be');
        await searchInput(wrapper).trigger('keydown', { key: 'Backspace' });
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        expect(menu(wrapper).exists()).toBe(true);
    });
});

describe('filtering', () => {
    it('filters grouped options by label, dropping empty groups', async () => {
        const wrapper = mountSelect({ options: GROUPED });
        await open(wrapper);
        expect(wrapper.findAll('h6')).toHaveLength(2);

        await searchInput(wrapper).setValue('slug');
        expect(wrapper.findAll('h6')).toHaveLength(1);
        expect(wrapper.find('h6').text()).toBe('Element');
        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(wrapper.find('li').attributes('data-flat-idx')).toBe('0'); // reindexed flat
    });

    it('shows the no-match copy when the query filters everything out', async () => {
        const wrapper = mountSelect({ options: GROUPED });
        await open(wrapper);

        await searchInput(wrapper).setValue('zzz');
        expect(wrapper.find('.influx-searchable-select-empty').text()).toContain('zzz');
    });

    it('hides the empty-value sentinel while filtering', async () => {
        const wrapper = mountSelect({ options: [
            { value: '', label: 'no mapping' },
            { value: 'a', label: 'no match here' },
        ] });
        await open(wrapper);
        expect(wrapper.findAll('li')).toHaveLength(2);
        expect(wrapper.find('li.is-empty').exists()).toBe(true);

        await searchInput(wrapper).setValue('no');
        const rows = wrapper.findAll('li');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toBe('no match here');
    });

    it('keeps value="" findable when emptyIsValue', async () => {
        const wrapper = mountSelect({ emptyIsValue: true, options: [
            { value: '', label: 'Auto-detect' },
            { value: 'a', label: 'Custom' },
        ] });
        await open(wrapper);

        await searchInput(wrapper).setValue('auto');
        const rows = wrapper.findAll('li');
        expect(rows).toHaveLength(1);
        expect(rows[0].classes()).not.toContain('is-empty');
        expect(rows[0].text()).toBe('Auto-detect');
    });

    it('wraps matches in <mark> and escapes HTML in labels', async () => {
        const wrapper = mountSelect();
        await open(wrapper);

        await searchInput(wrapper).setValue('raw');
        expect(wrapper.find('li .label').element.innerHTML)
            .toBe('Gamma &lt;b&gt;<mark>raw</mark>&lt;/b&gt;');
    });
});

describe('rendering modes', () => {
    it('renders flat options as a single list without the group scroller', async () => {
        const wrapper = mountSelect();
        await open(wrapper);

        expect(wrapper.find('.influx-searchable-select-scroll').exists()).toBe(false);
        expect(wrapper.findAll('ul.influx-searchable-select-options')).toHaveLength(1);
        expect(wrapper.find('h6').exists()).toBe(false);
    });

    it('renders grouped options inside one scroll region with kind-colored chips', async () => {
        const wrapper = mountSelect({ options: GROUPED });
        await open(wrapper);

        const scroll = wrapper.find('.influx-searchable-select-scroll');
        expect(scroll.exists()).toBe(true);
        expect(scroll.findAll('ul.influx-token-group')).toHaveLength(2);
        expect(scroll.findAll('h6')).toHaveLength(2);
        const chip = scroll.find('.influx-tokenized-chip-inline');
        expect(chip.attributes('data-kind')).toBe('element');
        expect(chip.text()).toBe('{id}');
    });

    it('shows the selected label, raw value fallback, or placeholder on the trigger', () => {
        const value = (w) => w.find('.influx-searchable-select-trigger .value');
        expect(value(mountSelect({ modelValue: 'beta' })).text()).toBe('Beta');
        expect(value(mountSelect({ modelValue: 'ghost' })).text()).toBe('ghost');

        const empty = mountSelect({ placeholder: 'Pick one' });
        expect(value(empty).text()).toBe('Pick one');
        expect(value(empty).classes()).toContain('placeholder');
    });
});

describe('drop direction', () => {
    it('drops up when the space below is under the threshold and above is larger', async () => {
        const wrapper = mountSelect();
        const ih = window.innerHeight;
        wrapper.element.getBoundingClientRect = () => ({
            top: ih - 40, bottom: ih - 6, left: 0, right: 200, width: 200, height: 34,
        });

        await open(wrapper);
        expect(wrapper.classes()).toContain('drop-up');
    });

    it('drops down when there is room below', async () => {
        const wrapper = mountSelect();
        wrapper.element.getBoundingClientRect = () => ({
            top: 10, bottom: 44, left: 0, right: 200, width: 200, height: 34,
        });

        await open(wrapper);
        expect(wrapper.classes()).not.toContain('drop-up');
    });
});
