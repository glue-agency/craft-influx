import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DrillList from '../DrillList.vue';
import { t } from '../../lib/installT.js';

/**
 * The drilled left pane. Locks in what a reader navigates by: the back header
 * (whose parent they're inside), the sub-strip (which field they came in
 * through) and the child rows — plus the selection contract the host drives the
 * right pane from.
 *
 * A child row is one line, same as the item rows it stands in for: its label and
 * an action badge. No note line under it — asserted, since that's a deliberate
 * match to the item list rather than an omission.
 *
 * The label is the child's own title; the middle child has none (a block the
 * sync would add, whose feed maps no title), which pins the zero-padded-ordinal
 * fallback.
 */
const children = [
    { title: 'Werfkelder', blockType: 'text', element: { id: 512, title: 'Werfkelder' }, action: 'unchanged', mappings: [{ changed: false }, { changed: false }] },
    { title: null, blockType: 'image', element: null, action: 'would-add', mappings: [{ changed: true }] },
    { title: 'Video', blockType: 'video', element: { id: 514, title: 'Video' }, action: 'would-remove', mappings: [] },
];

const mountList = (props = {}) => mount(DrillList, {
    props: {
        parentTitle: 'Werfkelder',
        parentAction: 'would-update',
        fieldLabel: 'Content blocks',
        fieldNode: 'blocks',
        childrenType: 'blocks',
        children,
        selectedIndex: 0,
        ...props,
    },
    global: { mocks: { $t: t } },
});

const items = (w) => w.findAll('.influx-drill-item');

describe('DrillList', () => {
    describe('back header', () => {
        it('carries the parent title, the way-out subtitle and the parent action badge', () => {
            const w = mountList();

            expect(w.find('.influx-drill-back-title').text()).toBe('Werfkelder');
            expect(w.find('.influx-drill-back-sub').text()).toBe('Back to parent');
            expect(w.find('.influx-drill-back-badge').text()).toBe('would-update');
        });

        it('emits back on click', async () => {
            const w = mountList();
            await w.find('.influx-drill-back').trigger('click');

            expect(w.emitted('back')).toHaveLength(1);
        });
    });

    describe('sub-strip', () => {
        it('names the field, its feed node and the child count', () => {
            const w = mountList();

            expect(w.find('.influx-drill-strip-label').text()).toBe('Content blocks');
            expect(w.find('.influx-drill-strip-node').text()).toBe('blocks');
            expect(w.find('.influx-drill-strip-count').text()).toBe('3 blocks');
        });

        it('shows no node line for a node-less mapping', () => {
            expect(mountList({ fieldNode: '' }).find('.influx-drill-strip-node').exists()).toBe(false);
        });

        it('names the count by children type, falling back to elements', () => {
            const count = (childrenType) => mountList({ childrenType }).find('.influx-drill-strip-count').text();

            expect(count('rows')).toBe('3 rows');
            expect(count('assets')).toBe('3 assets');
            expect(count('entries')).toBe('3 entries');
            expect(count('users')).toBe('3 users');
            expect(count('categories')).toBe('3 categories');
            expect(count('tags')).toBe('3 tags');
            expect(count('elements')).toBe('3 elements');
            expect(count('')).toBe('3 elements');
        });
    });

    describe('child rows', () => {
        it('renders one row per child', () => {
            expect(items(mountList())).toHaveLength(3);
        });

        it('labels each row by the child title, falling back to a zero-padded ordinal', () => {
            const w = mountList();

            expect(w.findAll('.influx-drill-item-title').map((el) => el.text())).toEqual(['Werfkelder', '02', 'Video']);
            expect(w.findAll('.influx-drill-item-badge').map((b) => b.text())).toEqual(['unchanged', 'would-add', 'would-remove']);
        });

        it('never renders an ordinal pill beside the label', () => {
            expect(mountList().find('.influx-drill-index').exists()).toBe(false);
        });

        it('says nothing under the label — a row is one line, like the item rows', () => {
            const removed = items(mountList())[2];

            // The label and the badge, and nothing else: a removed child says
            // it's not in the feed through its badge, not a note.
            expect(removed.element.children).toHaveLength(2);
            expect(removed.find('.influx-drill-item-badge').text()).toBe('would-remove');
        });
    });

    describe('selection', () => {
        it('marks the selected row and nothing else', () => {
            const w = mountList({ selectedIndex: 1 });

            expect(items(w).map((i) => i.classes('is-selected'))).toEqual([false, true, false]);
        });

        it('emits select with the row index', async () => {
            const w = mountList();
            await items(w)[2].trigger('click');

            expect(w.emitted('select')).toEqual([[2]]);
        });
    });
});
