import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import DebugApp from '../DebugApp.vue';

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const mountApp = (config = {}) => mount(DebugApp, {
    props: { config: { inspectUrl: '/inspect', sites: [], offsetHandles: [], links: [], limit: 25, ...config } },
    global: { mocks: { $t } },
});

const twoItems = {
    meta: { url: 'https://x', itemsOnPage: 2, limit: 25 },
    items: [
        { action: 'would-update', matchValue: 'a', mappings: [], raw: {} },
        { action: 'would-create', matchValue: 'b', mappings: [], raw: {} },
    ],
};

const child = (title, action, mappings = []) => ({ title, element: null, action, mappings });

// The first item's row nests elements, and its first child nests again — enough
// to prove the drill stack descends level by level rather than one deep.
const nestedItems = {
    meta: { url: 'https://x', itemsOnPage: 2, matchAttribute: 'external_id' },
    items: [
        {
            action: 'would-update',
            matchValue: 'a',
            element: { title: 'Werfkelder' },
            mappings: [
                {
                    handle: 'content_blocks',
                    label: 'Content blocks',
                    node: 'blocks',
                    childrenType: 'blocks',
                    children: [
                        child('Tekst', 'unchanged', [
                            {
                                handle: 'gallery',
                                label: 'Gallery',
                                node: 'images',
                                childrenType: 'assets',
                                children: [child('photo.jpg', 'would-add', [{ handle: 'alt', label: 'Alt', changed: true }])],
                            },
                        ]),
                        child('Afbeelding', 'would-add', [{ handle: 'image', label: 'Image', changed: true }]),
                    ],
                },
            ],
            raw: {},
        },
        { action: 'would-create', matchValue: 'b', mappings: [], raw: {} },
    ],
};

describe('DebugApp', () => {
    afterEach(() => {
        window.Craft.sendActionRequest = () => Promise.resolve({ data: {} });
    });

    it('inspects on mount and fills the split from one fetch', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: twoItems }));

        const w = mountApp();
        await flushPromises();

        expect(window.Craft.sendActionRequest).toHaveBeenCalled();
        expect(window.Craft.sendActionRequest.mock.calls[0][1]).toContain('/inspect');
        // One list row per fetched item; the first is selected + detailed.
        expect(w.findAll('.influx-split-item').length).toBe(2);
        expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').matchValue).toBe('a');
    });

    it('shows the clicked item in the detail pane', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: twoItems }));

        const w = mountApp();
        await flushPromises();

        await w.findAll('.influx-split-item')[1].trigger('click');

        expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').matchValue).toBe('b');
    });

    it('re-inspects in place when the form is submitted', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: { meta: { url: 'x' }, items: [] } }));

        const w = mountApp();
        await flushPromises();
        window.Craft.sendActionRequest.mockClear();

        await w.find('form').trigger('submit');
        await flushPromises();

        expect(window.Craft.sendActionRequest).toHaveBeenCalledTimes(1);
    });

    it('remounts the detail on re-inspect so per-item state cannot go stale', async () => {
        const payload = (action) => Promise.resolve({
            data: { meta: { url: 'x' }, items: [{ action, matchValue: 'a', mappings: [], raw: {} }] },
        });
        window.Craft.sendActionRequest = vi.fn(() => payload('would-update'));

        const w = mountApp();
        await flushPromises();
        const first = w.findComponent({ name: 'DebugItemDetail' });
        expect(first.props('row').action).toBe('would-update');

        window.Craft.sendActionRequest = vi.fn(() => payload('would-create'));
        await w.find('form').trigger('submit');
        await flushPromises();

        const second = w.findComponent({ name: 'DebugItemDetail' });
        expect(second.props('row').action).toBe('would-create');
        expect(second.vm).not.toBe(first.vm);
    });

    it('hangs the resize handle on the seam, between the list and the detail', () => {
        const split = mountApp().find('.influx-split').element;

        expect([...split.children].map((el) => el.classList[0])).toEqual([
            'influx-split-list',
            'influx-split-resizer',
            'influx-split-detail',
        ]);
    });

    it('surfaces a failed fetch as an error', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.reject(new Error('boom')));

        const w = mountApp();
        await flushPromises();

        expect(w.find('.influx-debug-error').exists()).toBe(true);
    });

    describe('drill-down', () => {
        // Inspect, then go into the selected item's nesting row.
        const drilled = async () => {
            window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: nestedItems }));

            const w = mountApp();
            await flushPromises();
            await w.find('.influx-detail-row--drill').trigger('click');

            return w;
        };

        it('swaps the item list for the child list, without a further fetch', async () => {
            const w = await drilled();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(true);
            expect(w.findAll('.influx-split-item').length).toBe(0);
            expect(w.findAll('.influx-drill-item').length).toBe(2);
            // The swap happens inside the list pane, so the seam handle — and
            // the width it holds on the container — is untouched by it.
            expect(w.find('.influx-split-resizer').exists()).toBe(true);
            // The back header names the item drilled out of.
            expect(w.find('.influx-drill-back-title').text()).toBe('Werfkelder');
            expect(window.Craft.sendActionRequest).toHaveBeenCalledTimes(1);
        });

        it('renders the selected child on the right, as a drilled detail', async () => {
            const detail = (await drilled()).findComponent({ name: 'DebugItemDetail' });

            expect(detail.props('row').title).toBe('Tekst');
            expect(detail.props('drilled')).toBe(true);
            expect(detail.props('indexLabel')).toBe('01');
            // The match key belongs to the item, not to a child of it.
            expect(detail.props('matchAttribute')).toBe('');
        });

        it('switches the right pane to the child the drill list selects', async () => {
            const w = await drilled();
            await w.findAll('.influx-drill-item')[1].trigger('click');

            const detail = w.findComponent({ name: 'DebugItemDetail' });
            expect(detail.props('row').title).toBe('Afbeelding');
            expect(detail.props('indexLabel')).toBe('02');
        });

        it('descends again from a child’s own nesting row, and pops back one level at a time', async () => {
            const w = await drilled();

            // The child's own drill row — one level deeper.
            await w.find('.influx-detail-row--drill').trigger('click');
            expect(w.findAll('.influx-drill-item').length).toBe(1);
            expect(w.find('.influx-drill-strip-label').text()).toBe('Gallery');
            // Now the back header names the child, not the item.
            expect(w.find('.influx-drill-back-title').text()).toBe('Tekst');
            expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').title).toBe('photo.jpg');

            await w.find('.influx-drill-back').trigger('click');

            expect(w.find('.influx-drill-strip-label').text()).toBe('Content blocks');
            expect(w.find('.influx-drill-back-title').text()).toBe('Werfkelder');
        });

        it('restores the item list when the back header is used', async () => {
            const w = await drilled();
            await w.find('.influx-drill-back').trigger('click');

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(false);
            expect(w.findAll('.influx-split-item').length).toBe(2);
            const detail = w.findComponent({ name: 'DebugItemDetail' });
            expect(detail.props('row').matchValue).toBe('a');
            expect(detail.props('drilled')).toBe(false);
        });

        it('drops the drill when another item is selected', async () => {
            const w = await drilled();

            w.vm.selectedIndex = 1;
            await flushPromises();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(false);
            expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').matchValue).toBe('b');
        });

        it('drops the drill on a fresh inspect', async () => {
            const w = await drilled();

            window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: twoItems }));
            await w.find('form').trigger('submit');
            await flushPromises();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(false);
            expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').matchValue).toBe('a');
        });
    });
});
