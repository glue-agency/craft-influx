import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../../api.js', () => ({
    defaultOptions: vi.fn(),
    renderElementSelect: vi.fn(),
    renderIconPicker: vi.fn(),
}));

import * as api from '../../api.js';
import MappingCell from '../MappingCell.vue';
import { CONTROLS, controlFor } from '../registry.js';

/**
 * The point of the registry, asserted as behaviour: a cell renders whatever
 * control the node's `type` names, and nothing in the cell asks what KIND of
 * field it belongs to. Adding a control kind is a component plus one registry
 * line — these tests are what would fail if a branch crept back in.
 */

const mountCell = (nodes, props = {}) => mount(MappingCell, {
    props: { nodes, region: 'default', mapping: {}, ...props },
    global: { mocks: { $t: (s) => s } },
});

describe('the control registry', () => {
    it('maps each declared type to its component', () => {
        expect(controlFor({ type: 'select' }).name).toBe('SelectField');
        expect(controlFor({ type: 'multiSelect' }).name).toBe('SelectField');
        expect(controlFor({ type: 'element' }).name).toBe('ElementField');
        expect(controlFor({ type: 'icon' }).name).toBe('IconField');
        expect(controlFor({ type: 'text' }).name).toBe('TextField');
    });

    it('falls back to the text control for a type nothing claims', () => {
        // A third-party kind pushed through SchemaBuilder::node() still renders,
        // still reads and still writes its slot rather than vanishing.
        expect(controlFor({ type: 'colorPicker' })).toBe(CONTROLS.text);
        expect(controlFor(undefined)).toBe(CONTROLS.text);
    });
});

describe('MappingCell', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        api.renderIconPicker.mockResolvedValue({ html: '', jsSettings: { id: 'x', freeOnly: true } });
        api.renderElementSelect.mockResolvedValue({ html: '', jsSettings: {} });
    });

    it('renders nothing for a region nobody declared', () => {
        // Which is how a row whose value comes entirely from its sub-mappings says
        // it has no cell — no flag involved.
        expect(mountCell([]).html()).toBe('');
    });

    it('dispatches on the node type alone', () => {
        expect(mountCell([{ type: 'icon' }]).findComponent({ name: 'IconField' }).exists()).toBe(true);
        expect(mountCell([{ type: 'text' }]).find('input.text').exists()).toBe(true);
    });

    it('gives a note its trailing link, and escapes the text either way', () => {
        // The text is escaped, so a note that needs to point somewhere carries
        // the target as its own key rather than markup inside it.
        const cell = mountCell([{
            type: 'note',
            text: 'Map one node holding the whole table. <b>x</b>',
            url: 'https://example.test/docs',
            linkText: 'Feed format',
        }]);
        const link = cell.find('p.light a');

        expect(cell.find('p.light b').exists()).toBe(false);
        expect(link.attributes('href')).toBe('https://example.test/docs');
        expect(link.attributes('rel')).toContain('noopener');
        expect(link.text()).toBe('Feed format');
    });

    it('renders a plain note without a link', () => {
        expect(mountCell([{ type: 'note', text: 'Nothing to map yet.' }]).find('p.light a').exists()).toBe(false);
    });

    it('leaves an unpicked default select reading empty', () => {
        // Not "—". The copy lives in the list, where the open menu says it in full
        // ("— no default —"), so the CLOSED cell looks like every other empty field
        // on the row — the same call the source cell makes about "— no mapping —".
        const cell = mountCell([{
            type:            'select',
            options:         [{ value: 'l', label: 'Large' }],
            sentinelOptions: [{ value: '', label: '— no default —' }],
        }]);
        const trigger = cell.find('.influx-searchable-select-trigger .value');

        expect(trigger.text()).toBe('');
        expect(trigger.classes()).toContain('placeholder');
    });

    it('seeds the control from the region slot', () => {
        const cell = mountCell([{ type: 'text' }], { mapping: { default: 'Untitled' } });

        expect(cell.find('input.text').element.value).toBe('Untitled');
    });

    it('emits the whole new mapping on a write', () => {
        // The row owns the store, so a cell can't half-apply a change that touches
        // two slots — which the source cell's sentinel does.
        const cell = mountCell([{ type: 'text' }]);

        cell.find('input.text').setValue('Untitled');

        expect(cell.emitted('update:mapping')[0]).toEqual([{ default: 'Untitled' }]);
    });

    it('hands a select the renderer options only when the node carries none', () => {
        const supplied = [{ value: 'a', label: 'A' }];
        const own = [{ value: 'b', label: 'B' }];

        const merged = mountCell([{ type: 'select' }], { options: supplied });
        expect(merged.findComponent({ name: 'SelectField' }).props('options')).toEqual(supplied);

        const kept = mountCell([{ type: 'select', options: own }], { options: supplied });
        expect(kept.findComponent({ name: 'SelectField' }).props('options')).toBeNull();
    });

    it('passes the picker handle to a server-rendered control', async () => {
        const cell = mountCell([{ type: 'icon' }], { pickerHandle: 'test_icon' });
        await flushPromises();

        expect(cell.findComponent({ name: 'IconField' }).props('fieldHandle')).toBe('test_icon');
    });

    it('does not let a node it renders leak onto the control as an attribute', () => {
        // Every control is bound the same interface, so one that doesn't read
        // `options` or `fieldHandle` must not stamp them on its root element.
        const cell = mountCell([{ type: 'text' }], { pickerHandle: 'test_field', options: [] });
        const input = cell.find('input.text');

        expect(input.attributes('options')).toBeUndefined();
        expect(input.attributes('field-handle')).toBeUndefined();
    });
});

describe('SelectField lazy options', () => {
    const lazyNode = { type: 'select', lazy: true, searchable: true };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('fetches nothing until the dropdown is opened', () => {
        mountCell([lazyNode], { pickerHandle: 'test_country' });

        expect(api.defaultOptions).not.toHaveBeenCalled();
    });

    it('fetches once, keyed by the field handle', async () => {
        api.defaultOptions.mockResolvedValue({ options: [{ value: 'BE', label: 'Belgium' }] });
        const cell = mountCell([lazyNode], { pickerHandle: 'test_country' });
        const select = cell.findComponent({ name: 'SearchableSelect' });

        select.vm.$emit('open');
        await flushPromises();
        select.vm.$emit('open');
        await flushPromises();

        expect(api.defaultOptions).toHaveBeenCalledExactlyOnceWith('test_country');
        expect(select.props('options')).toEqual([{ value: 'BE', label: 'Belgium' }]);
    });

    it('survives a failed fetch with an empty list rather than throwing', async () => {
        api.defaultOptions.mockRejectedValue(new Error('boom'));
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const cell = mountCell([lazyNode], { pickerHandle: 'test_country' });
        const select = cell.findComponent({ name: 'SearchableSelect' });

        select.vm.$emit('open');
        await flushPromises();

        expect(select.props('options')).toEqual([]);
    });

    /**
     * The sentinel rides the NODE while the options ride the fetch, so
     * "— no default —" is pickable before the list resolves — and the endpoint
     * answering that fetch returns none of its own
     * ({@see LinkBuilderService::defaultOptionsFor}). Both leading with one is how
     * the row used to show two.
     */
    it('offers a lazy node’s sentinel before its list has been fetched', async () => {
        api.defaultOptions.mockResolvedValue({ options: [{ value: 'BE', label: 'Belgium' }] });
        const sentinels = [{ value: '', label: '— no default —' }];
        const cell = mountCell([{ ...lazyNode, sentinelOptions: sentinels }], { pickerHandle: 'test_country' });
        const select = cell.findComponent({ name: 'SearchableSelect' });

        expect(select.props('options')).toEqual([{ label: null, kind: null, options: sentinels }]);

        select.vm.$emit('open');
        await flushPromises();

        expect(select.props('options')).toEqual([
            { label: null, kind: null, options: sentinels },
            { label: null, kind: null, options: [{ value: 'BE', label: 'Belgium' }] },
        ]);
    });

    it('leaves an eager node alone — its options came with the descriptor', async () => {
        const cell = mountCell([{ type: 'select', options: [{ value: 'red', label: 'Red' }] }]);

        cell.findComponent({ name: 'SearchableSelect' }).vm.$emit('open');
        await flushPromises();

        expect(api.defaultOptions).not.toHaveBeenCalled();
    });
});
