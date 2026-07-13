import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LogApp from '../LogApp.vue';

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const baseConfig = (over = {}) => ({
    log: { id: 1, linkHandle: 'news', trigger: 'cp', status: 'ok', startedAt: 'now', finishedAt: 'later', error: null, itemsSeen: 2, itemsCreated: 1, itemsUpdated: 0, itemsUnchanged: 0, itemsSkipped: 1, itemsDeleted: 0, itemsDisabled: 0 },
    // The bootstrap ships only page 1 (newest first).
    items: [
        { id: 2, action: 'skipped', matchValue: 'B', message: 'missing id', title: 'Item B', errorCount: 0 },
        { id: 1, action: 'created', matchValue: 'A', message: '', title: 'Item A', errorCount: 0 },
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

describe('LogApp', () => {
    afterEach(() => {
        window.Craft.sendActionRequest = () => Promise.resolve({ data: {} });
        // Filters write to the URL — reset it so it can't leak between tests.
        window.history.replaceState({}, '', '/');
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
});
