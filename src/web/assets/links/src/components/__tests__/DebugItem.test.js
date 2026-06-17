import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DebugItem from '../DebugItem.vue';

/**
 * Renders the JSON `row` from DebugService::debugItem(): a status-coloured
 * action tag, element link, the field-comparison grid (friendly name above
 * handle, changed-row flag, native n/a) and a raw-JSON disclosure.
 */
const mountItem = (row) => mount(DebugItem, {
    props: { row },
    global: { mocks: { $t: (s) => s } },
});

const baseRow = (over = {}) => ({
    action: 'would-update',
    matchValue: 'ABC',
    element: { id: 42, title: 'Thing', cpEditUrl: '/admin/x/42' },
    message: null,
    error: null,
    raw: { id: 1 },
    mappings: [],
    ...over,
});

describe('DebugItem', () => {
    it('renders the action as a status-coloured tag', () => {
        const tag = mountItem(baseRow({ action: 'would-update' })).find('.influx-debug-tag');

        expect(tag.text()).toBe('would-update');
        expect(tag.classes()).toContain('live');
    });

    it('maps skip/error actions to the right colours', () => {
        expect(mountItem(baseRow({ action: 'would-skip' })).find('.influx-debug-tag').classes()).toContain('pending');
        expect(mountItem(baseRow({ action: 'error' })).find('.influx-debug-tag').classes()).toContain('expired');
    });

    it('links the element when present', () => {
        const a = mountItem(baseRow()).find('.influx-debug-item-element a');

        expect(a.attributes('href')).toBe('/admin/x/42');
        expect(a.text()).toContain('Thing');
    });

    it('shows "will be created" for a new would-create item', () => {
        const w = mountItem(baseRow({ action: 'would-create', element: null }));

        expect(w.find('.influx-debug-item-element').text()).toContain('will be created');
    });

    it('renders field rows: name above handle, changed flag, native n/a', () => {
        const w = mountItem(baseRow({
            mappings: [
                { handle: 'title', label: 'Title', node: 'name', default: null, native: false, rawValue: 'X', parsedValue: 'X', currentValue: 'Y', changed: true, note: null, error: null },
                { handle: 'slug', label: 'Slug', node: null, default: null, native: true, rawValue: null, parsedValue: null, currentValue: null, changed: false, note: null, error: null },
            ],
        }));
        const rows = w.findAll('.influx-debug-field-row');

        expect(rows).toHaveLength(2);
        expect(rows[0].find('.name').text()).toBe('Title');
        expect(rows[0].find('.handle').text()).toContain('title');
        expect(rows[0].attributes('data-changed')).toBe('true');

        // changed:false → no data-changed attribute; native → handle notes it.
        expect(rows[1].attributes('data-changed')).toBeUndefined();
        expect(rows[1].find('.handle').text()).toContain('native');
    });

    it('renders a raw JSON disclosure', () => {
        const pre = mountItem(baseRow({ raw: { a: 1 } })).find('.influx-debug-raw pre');

        expect(pre.text()).toContain('"a": 1');
    });

    it('shows message and error notes', () => {
        const notes = mountItem(baseRow({ message: 'skipped because', error: 'boom' })).findAll('.influx-debug-note');

        expect(notes.some((n) => n.text().includes('skipped because'))).toBe(true);
        expect(notes.some((n) => n.text().includes('boom'))).toBe(true);
    });
});
