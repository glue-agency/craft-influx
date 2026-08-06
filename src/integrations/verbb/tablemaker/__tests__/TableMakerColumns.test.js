import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TableMakerColumns from '../resources/TableMakerColumns.vue';

/**
 * The column editor for a Table Maker mapping — the one control that edits
 * configuration and mappings together, because the mapping rows ARE the declared
 * columns and neither half is on the server to derive from.
 */

const node = {
    type: 'tableMakerColumns',
    handle: 'columns',
    label: 'Columns',
    columnTypes: { singleline: 'Single-line text', time: 'Time', lightswitch: 'Lightswitch' },
    enableWidth: true,
    enableAlign: true,
};

const twoColumns = [
    { id: 'c1', heading: 'Day', type: 'singleline', align: '', width: '' },
    { id: 'c2', heading: 'From', type: 'time', align: 'left', width: '100' },
];

const mountCard = (channels = {}, props = {}) => mount(TableMakerColumns, {
    props: { node, channels, nodeOptions: [], ...props },
    global: { mocks: { $t: (s) => s }, stubs: { ElementPicker: true } },
});

const emitted = (w) => w.emitted('update:channels').at(-1)[0];

describe('TableMakerColumns', () => {
    it('starts empty, with a hint rather than a headless table', () => {
        const w = mountCard();

        expect(w.find('.influx-tablemaker-columns').exists()).toBe(false);
        expect(w.findComponent({ name: 'SubFieldRows' }).exists()).toBe(false);
        expect(w.find('.influx-mapping-group-empty').exists()).toBe(true);
    });

    it('leads the header with the action, then the pills', () => {
        // The order the cards' own "clear nodes" link sets, so the header chrome
        // reads the same wherever it appears.
        const w = mountCard({ options: { columns: twoColumns } });
        const header = w.find('.influx-mapping-group-header');

        const text = header.text();
        expect(text.indexOf('add column')).toBeGreaterThan(-1);
        expect(text.indexOf('add column')).toBeLessThan(text.lastIndexOf('2'));
        // Document order, not just visual: the action is the first of the two.
        expect([...header.element.querySelectorAll('.add-column, .pill-count')]
            .map((el) => el.className.split(' ')[0])).toEqual(['influx-clear-link', 'pill']);
    });

    it('writes a new column into options.columns, leaving fields alone', async () => {
        const w = mountCard({ options: { columns: [twoColumns[0]] }, fields: { c1: { node: 'x.day' } } });

        await w.find('.add-column').trigger('click');

        const { options, fields } = emitted(w);
        expect(options.columns).toHaveLength(2);
        expect(options.columns[1]).toEqual({ id: 'c2', heading: '', type: 'singleline', align: '', width: '' });
        expect(fields).toEqual({ c1: { node: 'x.day' } });
    });

    it('never reuses an id a removed column had', async () => {
        // The id is the only thing tying a column to its sub-mapping, so a
        // recycled one would inherit whatever the old column left behind.
        const w = mountCard({ options: { columns: twoColumns }, fields: {} });

        await w.find('.add-column').trigger('click');

        expect(emitted(w).options.columns[2].id).toBe('c3');
    });

    it('takes a column’s mapping with it when the column goes', async () => {
        const w = mountCard({
            options: { columns: twoColumns },
            fields: { c1: { node: 'x.day' }, c2: { node: 'x.from' } },
        });

        await w.findAll('.influx-tablemaker-actions button')[1].trigger('click');

        const { options, fields } = emitted(w);
        expect(options.columns.map((c) => c.id)).toEqual(['c1']);
        expect(fields).toEqual({ c1: { node: 'x.day' } });
    });

    it('prunes the columns key off options when the last column goes', async () => {
        const w = mountCard({ options: { columns: [twoColumns[0]] }, fields: {} });

        await w.find('.influx-tablemaker-actions button').trigger('click');

        expect(emitted(w).options).toEqual({});
    });

    it('edits a heading in place', async () => {
        const w = mountCard({ options: { columns: twoColumns }, fields: {} });

        await w.findAll('.influx-tablemaker-columns input[type="text"]')[0].setValue('Weekday');

        expect(emitted(w).options.columns[0].heading).toBe('Weekday');
        expect(emitted(w).options.columns[1]).toEqual(twoColumns[1]);
    });

    it('derives one mapping row per column, handled by its id', () => {
        const w = mountCard({ options: { columns: twoColumns }, fields: { c1: { node: 'x.day' } } });
        const rows = w.findComponent({ name: 'SubFieldRows' });

        expect(rows.props('node').subFields).toEqual([
            { handle: 'c1', label: 'Day', type: 'text' },
            { handle: 'c2', label: 'From', type: 'text' },
        ]);
        expect(rows.props('rows')).toEqual({ c1: { node: 'x.day' } });
    });

    it('gives a flag column the lightswitch its cell will actually store', () => {
        const w = mountCard({ options: { columns: [{ id: 'c1', heading: 'Active', type: 'lightswitch' }] } });

        expect(w.findComponent({ name: 'SubFieldRows' }).props('node').subFields[0].type).toBe('lightswitch');
    });

    it('labels an unnamed column rather than rendering a blank row', () => {
        const w = mountCard({ options: { columns: [{ id: 'c1', heading: '', type: 'singleline' }] } });

        expect(w.findComponent({ name: 'SubFieldRows' }).props('node').subFields[0].label).toBe('Untitled column');
    });

    it('writes a row change back without disturbing the columns', () => {
        const w = mountCard({ options: { columns: twoColumns }, fields: {} });

        w.findComponent({ name: 'SubFieldRows' }).vm.$emit('update:rows', { c2: { node: 'x.from' } });

        expect(emitted(w)).toEqual({ options: { columns: twoColumns }, fields: { c2: { node: 'x.from' } } });
    });

    it('drops the width and align sub-columns the field hides', () => {
        const w = mountCard({ options: { columns: twoColumns } }, {
            node: { ...node, enableWidth: false, enableAlign: false },
        });

        expect(w.findAll('.influx-tablemaker-columns thead th').map((h) => h.text()))
            .toEqual(['Heading', 'Type', '']);
    });

    it('survives stored config that isn’t a list of identified columns', () => {
        // Operator-authored JSON: a column with no id can't be mapped to
        // anything, and `columns` may not be a list at all.
        expect(mountCard({ options: { columns: 'nonsense' } }).find('.influx-mapping-group-empty').exists()).toBe(true);
        expect(mountCard({ options: { columns: [{ heading: 'Orphan' }] } })
            .find('.influx-mapping-group-empty').exists()).toBe(true);
    });

    it('offers no editing affordances when read-only', () => {
        const w = mountCard({ options: { columns: twoColumns } }, { readOnly: true });

        expect(w.find('.add-column').exists()).toBe(false);
        expect(w.find('.influx-tablemaker-actions button').exists()).toBe(false);
        expect(w.find('.influx-tablemaker-columns input[type="text"]').attributes('disabled')).toBeDefined();
    });
});
