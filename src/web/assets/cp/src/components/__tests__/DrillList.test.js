import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DrillList from '../DrillList.vue';
import { t } from '../../lib/installT.js';

/**
 * The drilled left pane. Locks in the three things a reader navigates by: the
 * back header (whose parent they're inside), the sub-strip (which field they
 * came in through) and the child rows' note lines (how much is in each) — plus
 * the selection contract the host drives the right pane from.
 */
const children = [
    { title: 'Tekst', blockType: 'text', element: null, action: 'unchanged', mappings: [{ changed: false }, { changed: false }] },
    { title: 'Afbeelding', blockType: 'image', element: null, action: 'would-add', mappings: [{ changed: true }] },
    { title: 'Video', blockType: 'video', element: null, action: 'would-remove', mappings: [] },
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
const notes = (w) => w.findAll('.influx-drill-item-sub').map((s) => s.text());

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
        it('renders one row per child, ordinals zero-padded', () => {
            const w = mountList();

            expect(items(w)).toHaveLength(3);
            expect(w.findAll('.influx-drill-index').map((p) => p.text())).toEqual(['01', '02', '03']);
        });

        it('heads each row with the child title and its action badge', () => {
            const w = mountList();

            expect(w.findAll('.influx-drill-item-title').map((el) => el.text())).toEqual(['Tekst', 'Afbeelding', 'Video']);
            expect(w.findAll('.influx-drill-item-badge').map((b) => b.text())).toEqual(['unchanged', 'would-add', 'would-remove']);
        });

        it('notes the field count, appending the change count only when there is one', () => {
            const [unchanged, added] = notes(mountList());

            expect(unchanged).toBe('2 fields');
            expect(added).toBe('1 field · 1 change');
        });

        it('notes a removed child as belonging to the element, not the feed', () => {
            expect(notes(mountList())[2]).toBe('In element, not in feed');

            const committed = [{ ...children[2], action: 'removed' }];

            expect(notes(mountList({ children: committed }))[0]).toBe('In element, not in feed');
        });

        it('pluralises the counts', () => {
            const many = [{ ...children[1], mappings: [{ changed: true }, { changed: true }, { changed: false }] }];

            expect(notes(mountList({ children: many }))[0]).toBe('3 fields · 2 changes');
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
