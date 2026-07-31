import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LogApp from '../LogApp.vue';
import { installVocabulary } from '../../lib/vocabulary.js';

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const baseConfig = (over = {}) => ({
    log: { id: 1, linkHandle: 'news', trigger: 'cp', triggerLabel: 'Control panel', user: null, status: 'ok', startedAt: 'now', finishedAt: 'later', error: null, itemsSeen: 2, itemsCreated: 1, itemsUpdated: 0, itemsUnchanged: 0, itemsSkipped: 1, itemsDeleted: 0, itemsDisabled: 0 },
    // The bootstrap ships only page 1 (newest first).
    items: [
        { id: 2, action: 'skipped', matchValue: 'B', message: 'missing id', title: 'Item B', trashed: false, errorCount: 0 },
        { id: 1, action: 'created', matchValue: 'A', message: '', title: 'Item A', trashed: false, errorCount: 0 },
    ],
    itemTotal: 2,
    perPage: 25,
    itemsUrl: '/items',
    itemUrlTemplate: '/items/__ID__',
    linkId: 7,
    linkName: 'News',
    isLive: false,
    ...over,
});

const mountApp = (over = {}) => mount(LogApp, {
    props: { config: baseConfig(over) },
    global: { mocks: { $t } },
});

// A fetched drill-down whose single mapping nests elements — the way into the
// drill stack.
const nestedRow = () => ({
    action: 'updated',
    matchValue: 'B',
    matchAttribute: 'external_id',
    mappings: [
        {
            handle: 'content_blocks',
            label: 'Content blocks',
            node: 'blocks',
            childrenType: 'blocks',
            children: [
                { title: 'Tekst', element: null, action: 'unchanged', mappings: [{ handle: 'body', label: 'Body', changed: false }] },
                { title: 'Afbeelding', element: null, action: 'would-add', mappings: [{ handle: 'image', label: 'Image', changed: true }] },
            ],
        },
    ],
    raw: {},
});

describe('LogApp', () => {
    afterEach(() => {
        window.Craft.sendActionRequest = () => Promise.resolve({ data: {} });
        // Filters write to the URL — reset it so it can't leak between tests.
        window.history.replaceState({}, '', '/');
        // Restore the generated default after a test installs its own payload.
        installVocabulary(null);
    });

    it('renders the counters and the first page of items', () => {
        const w = mountApp();

        expect(w.findAll('.influx-split-item').length).toBe(2);
        // Seven counters (seen + six actions); "seen" shows the itemsSeen total.
        const counters = w.findAll('.influx-counter');
        expect(counters.length).toBe(7);
        expect(counters[0].text()).toContain('2');
        expect(counters[0].text().toLowerCase()).toContain('seen');
    });

    it('names the user a run was triggered by, next to the trigger label', () => {
        const w = mountApp({ log: { ...baseConfig().log, user: 'Ada Lovelace' } });
        const meta = w.find('.influx-log-meta').text();

        // The label, never the raw stored value — the trigger is the mechanism,
        // the user is who asked for it.
        expect(meta).toContain('Control panel');
        expect(meta).not.toContain('cp');
        expect(meta).toContain('by Ada Lovelace');
    });

    it('leaves the meta line userless for a run nobody triggered', () => {
        expect(mountApp().find('.influx-log-meta').text()).not.toContain('by ');
    });

    it('pills an item whose element sits in the trash, and only that one', () => {
        const w = mountApp({
            items: [
                { id: 2, action: 'deleted', matchValue: 'B', message: '', title: 'Item B', trashed: true, errorCount: 0 },
                { id: 1, action: 'created', matchValue: 'A', message: '', title: 'Item A', trashed: false, errorCount: 0 },
            ],
        });

        const rows = w.findAll('.influx-split-item');
        expect(rows[0].find('.influx-split-item-trashed').text()).toBe('In trash');
        expect(rows[0].find('.influx-split-item-title').text()).toBe('Item B');
        expect(rows[1].find('.influx-split-item-trashed').exists()).toBe(false);
    });

    it('hangs the resize handle on the seam, between the list and the detail', () => {
        const split = mountApp().find('.influx-split').element;

        expect([...split.children].map((el) => el.classList[0])).toEqual([
            'influx-split-list',
            'influx-split-resizer',
            'influx-split-detail',
        ]);
    });

    it('renders the counter row the server ships, labels and all', () => {
        installVocabulary({
            counters: [
                { key: 'itemsSeen', action: null, label: 'gezien', tone: null },
                { key: 'itemsCreated', action: 'created', label: 'aangemaakt', tone: 'good' },
            ],
        });

        const counters = mountApp().findAll('.influx-counter');

        expect(counters.length).toBe(2);
        expect(counters[0].text()).toContain('gezien');
        expect(counters[1].text()).toContain('aangemaakt');
        // Non-zero + tone 'good' → the green tint; zero would mute it.
        expect(counters[1].find('.influx-counter-value').classes()).toContain('is-good');
    });

    it('names the active filter with the counter label the server shipped', async () => {
        installVocabulary({
            counters: [
                { key: 'itemsSeen', action: null, label: 'gezien', tone: null },
                { key: 'itemsCreated', action: 'created', label: 'aangemaakt', tone: 'good' },
            ],
        });
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: { items: [], total: 0, counters: {}, done: false } }));

        const w = mountApp();
        await w.findAll('.influx-counter')[1].trigger('click');
        await flushPromises();

        // The translated label, not the raw 'created' action value the filter
        // travels as.
        expect(w.find('.influx-split-list-hint').text()).toBe('showing aangemaakt');
        expect(w.find('.influx-split-empty').text()).toBe('No aangemaakt items');
    });

    it('filters the list by action when a counter is clicked', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({
            data: { items: [{ id: 1, action: 'created', matchValue: 'A', message: '', title: 'Item A', errorCount: 0 }], total: 1, counters: {}, done: false },
        }));

        const w = mountApp();
        await flushPromises(); // mount auto-selects the first item (a drill-down fetch)

        // Counters: [seen, created, updated, ...] — index 1 is "created".
        await w.findAll('.influx-counter')[1].trigger('click');
        await flushPromises();

        // The list re-fetch (not the item drill-downs) carries the filter.
        const listCall = window.Craft.sendActionRequest.mock.calls.find((c) => c[1].includes('status=created'));
        expect(listCall).toBeTruthy();
        expect(listCall[1]).toContain('/items');
        expect(w.findAll('.influx-split-item').length).toBe(1);
        // …and the filter is reflected in the URL (bookmarkable).
        expect(window.location.search).toContain('status=created');
    });

    it('applies the ?status filter from the URL on mount', async () => {
        window.history.replaceState({}, '', '/?status=updated');
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: { items: [], total: 0, counters: {}, done: false } }));

        mountApp({ isLive: false });
        await flushPromises();

        const listCall = window.Craft.sendActionRequest.mock.calls.find((c) => c[1].includes('status=updated'));
        expect(listCall).toBeTruthy();
    });

    it('opens the first item’s drill-down on mount (finished log), not the list', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({
            data: { row: { action: 'skipped', matchValue: 'B', mappings: [], raw: {} } },
        }));

        const w = mountApp({ isLive: false });
        await flushPromises();

        // One request — the first item's drill-down, not a list re-fetch.
        expect(window.Craft.sendActionRequest).toHaveBeenCalledTimes(1);
        expect(window.Craft.sendActionRequest.mock.calls[0][1]).toBe('/items/2');
        expect(w.findComponent({ name: 'DebugItemDetail' }).exists()).toBe(true);
    });

    it('lazily fetches a clicked item’s drill-down', async () => {
        window.Craft.sendActionRequest = vi.fn((m, url) => {
            const id = url.split('/').pop();
            return Promise.resolve({ data: { row: { action: id === '1' ? 'created' : 'skipped', matchValue: id, mappings: [], raw: {} } } });
        });

        const w = mountApp();
        await flushPromises(); // first item (id 2) auto-selected + fetched

        await w.findAll('.influx-split-item')[1].trigger('click'); // item id 1
        await flushPromises();

        expect(window.Craft.sendActionRequest.mock.calls.map((c) => c[1])).toContain('/items/1');
        expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').matchValue).toBe('1');
    });

    it('renders a single endpoint URL when supplied', () => {
        const w = mountApp({ endpointUrl: 'https://ex.test/api?language=fr' });

        const link = w.find('.influx-log-endpoint-url');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('language=fr');
        expect(w.findAll('.influx-log-endpoint-line').length).toBe(0);
    });

    it('lists per-site endpoints for an all-sites run over site endpoints', () => {
        const w = mountApp({
            endpointUrl: null,
            endpoints: [
                { site: 'nl', url: 'https://ex.test/api?language=nl' },
                { site: 'fr', url: 'https://ex.test/api?language=fr' },
            ],
        });

        const lines = w.findAll('.influx-log-endpoint-line');
        expect(lines.length).toBe(2);
        expect(lines[0].text()).toContain('nl');
        expect(lines[1].text()).toContain('language=fr');
    });

    it('renders the resource chip for a single-element run', () => {
        const w = mountApp({ resourceHtml: '<span class="chip">Unit A</span>' });

        const resource = w.find('.influx-log-resource');
        expect(resource.exists()).toBe(true);
        expect(resource.html()).toContain('Unit A');
    });

    it('fetches the current page on mount when live and refreshes counters', async () => {
        window.Craft.sendActionRequest = vi.fn((m, url) => {
            if (url.includes('/items/')) {
                return Promise.resolve({ data: { row: { action: 'created', matchValue: 'C', mappings: [], raw: {} } } });
            }

            return Promise.resolve({
                data: {
                    items: [{ id: 3, action: 'created', matchValue: 'C', message: '', title: 'C', errorCount: 0 }],
                    total: 1,
                    counters: { itemsSeen: 3, status: 'ok' },
                    done: true,
                },
            });
        });

        const w = mountApp({ isLive: true, log: { ...baseConfig().log, status: 'running' } });
        await flushPromises();

        // The item list was polled, and the seen counter reflects the refresh.
        const listCall = window.Craft.sendActionRequest.mock.calls.find((c) => !c[1].includes('/items/'));
        expect(listCall).toBeTruthy();
        expect(listCall[1]).toContain('/items');
        expect(w.findAll('.influx-counter')[0].text()).toContain('3');
    });

    describe('drill-down', () => {
        // Mount, auto-select the first item (whose row nests elements), and go
        // into its nesting row.
        const drilled = async () => {
            window.Craft.sendActionRequest = vi.fn((m, url) => (url.includes('/items/')
                ? Promise.resolve({ data: { row: nestedRow() } })
                : Promise.resolve({ data: { items: baseConfig().items, total: 2, counters: {}, done: false } })));

            const w = mountApp();
            await flushPromises();
            await w.find('.influx-detail-row--drill').trigger('click');

            return w;
        };

        it('swaps the item list for the child list, without a further request', async () => {
            const w = await drilled();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(true);
            expect(w.findAll('.influx-split-item').length).toBe(0);
            expect(w.findAll('.influx-drill-item').length).toBe(2);
            // The back header names the item drilled out of.
            expect(w.find('.influx-drill-back-title').text()).toBe('Item B');
            // The swap happens inside the list pane, so the seam handle — and
            // the width it holds on the container — is untouched by it.
            expect(w.find('.influx-split-resizer').exists()).toBe(true);
            // Only the one drill-down fetch the selection made.
            expect(window.Craft.sendActionRequest).toHaveBeenCalledTimes(1);
        });

        it('renders the selected child on the right, as a drilled detail', async () => {
            const detail = (await drilled()).findComponent({ name: 'DebugItemDetail' });

            expect(detail.props('row').title).toBe('Tekst');
            expect(detail.props('drilled')).toBe(true);
            expect(detail.props('fallbackLabel')).toBe('01');
        });

        it('switches the right pane to the child the drill list selects', async () => {
            const w = await drilled();
            await w.findAll('.influx-drill-item')[1].trigger('click');

            const detail = w.findComponent({ name: 'DebugItemDetail' });
            expect(detail.props('row').title).toBe('Afbeelding');
            expect(detail.props('fallbackLabel')).toBe('02');
        });

        it('restores the item list when the back header is used', async () => {
            const w = await drilled();
            await w.find('.influx-drill-back').trigger('click');

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(false);
            expect(w.findAll('.influx-split-item').length).toBe(2);
            const detail = w.findComponent({ name: 'DebugItemDetail' });
            expect(detail.props('row').matchValue).toBe('B');
            expect(detail.props('drilled')).toBe(false);
        });

        it('drops the drill when the selection moves to another item', async () => {
            const w = await drilled();

            w.vm.select(1);
            await flushPromises();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(false);
            expect(w.findAll('.influx-split-item').length).toBe(2);
        });

        it('keeps the drill through a poll that leaves the selection alone', async () => {
            const w = await drilled();

            w.vm.fetchPage(1);
            await flushPromises();

            expect(w.findComponent({ name: 'DrillList' }).exists()).toBe(true);
            expect(w.findComponent({ name: 'DebugItemDetail' }).props('row').title).toBe('Tekst');
        });
    });
});
