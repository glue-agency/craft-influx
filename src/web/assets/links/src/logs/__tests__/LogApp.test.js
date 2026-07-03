import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import LogApp from '../LogApp.vue';

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const baseConfig = (over = {}) => ({
    log: { id: 1, linkHandle: 'news', trigger: 'cp', status: 'ok', startedAt: 'now', finishedAt: 'later', error: null, itemsSeen: 2, itemsCreated: 1, itemsUpdated: 0, itemsUnchanged: 0, itemsSkipped: 1, itemsDeleted: 0, itemsDisabled: 0 },
    // The bootstrap ships only page 1 (newest first).
    items: [
        { id: 2, action: 'skipped', matchValue: 'B', message: '', elementHtml: null },
        { id: 1, action: 'created', matchValue: 'A', message: '', elementHtml: '<span>el</span>' },
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
    });

    it('renders the first page and the status menu options', async () => {
        const w = mountApp();

        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(2);

        // The status menu offers All + one row per action (no counts).
        await w.findComponent({ name: 'LogFilterMenu' }).find('.btn').trigger('click');
        const created = w.findAll('.influx-log-filter-option').find((o) => o.text().includes('created'));
        expect(created).toBeTruthy();
    });

    it('re-queries the server (page 1, single action) when a status is picked', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({
            data: { items: [{ id: 1, action: 'created', matchValue: 'A', message: '', elementHtml: null }], total: 1, counters: {}, done: false },
        }));

        const w = mountApp();
        await w.findComponent({ name: 'LogFilterMenu' }).find('.btn').trigger('click');
        const created = w.findAll('.influx-log-filter-option').find((o) => o.text().includes('created'));
        await created.trigger('click');
        await flushPromises();

        const url = window.Craft.sendActionRequest.mock.calls[0][1];
        expect(url).toContain('/items');
        expect(url).toContain('status=created');
        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(1);
    });

    it('searches server-side (debounced) over match value + message', async () => {
        vi.useFakeTimers();
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: { items: [], total: 0, counters: {}, done: false } }));

        const w = mountApp();
        await w.find('.influx-log-search input').setValue('abc');
        vi.advanceTimersByTime(300);
        vi.useRealTimers();
        await flushPromises();

        expect(window.Craft.sendActionRequest.mock.calls[0][1]).toContain('search=abc');
    });

    it('does not fetch on mount for a finished log', () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({ data: { items: [], total: 0, counters: {}, done: true } }));

        mountApp({ isLive: false });

        expect(window.Craft.sendActionRequest).not.toHaveBeenCalled();
    });

    it('renders a single endpoint URL when one is supplied', () => {
        const w = mountApp({ endpointUrl: 'https://ex.test/api?language=fr' });

        const link = w.find('.influx-log-endpoint-url');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('language=fr');
        // No per-site list when a single URL is shown.
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
        expect(lines[0].text()).toContain('language=nl');
        expect(lines[1].text()).toContain('fr');
        expect(lines[1].text()).toContain('language=fr');
    });

    it('renders the resource chip for a single-element run', () => {
        const w = mountApp({
            endpointUrl: '@syncUrl/api/properties/{importId}',
            resourceHtml: '<span class="chip">Unit A</span>',
        });

        const resource = w.find('.influx-log-resource');
        expect(resource.exists()).toBe(true);
        expect(resource.text()).toContain('Resource');
        expect(resource.html()).toContain('Unit A');
    });

    it('omits the resource row for whole-feed runs', () => {
        const w = mountApp({ endpointUrl: 'https://ex.test/api' });

        expect(w.find('.influx-log-resource').exists()).toBe(false);
    });

    it('shows site and offset cells only when the run recorded them', () => {
        const bare = mountApp();
        expect(bare.text()).not.toContain('Offset');

        const w = mountApp({
            log: { ...baseConfig().log, siteHandle: 'fr', offsetHandle: 'hour' },
        });

        const labels = w.findAll('.influx-log-cell').map((c) => c.text());
        expect(labels.some((t) => t.includes('Site') && t.includes('fr'))).toBe(true);
        expect(labels.some((t) => t.includes('Offset') && t.includes('hour'))).toBe(true);
    });

    it('fetches the current page on mount when live and refreshes counters', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({
            data: {
                items: [{ id: 3, action: 'created', matchValue: 'C', message: '', elementHtml: null }],
                total: 1,
                counters: { itemsSeen: 3, itemsCreated: 1, status: 'ok' },
                done: true,
            },
        }));

        const w = mountApp({ isLive: true, log: { ...baseConfig().log, status: 'running' } });
        await flushPromises();

        expect(window.Craft.sendActionRequest).toHaveBeenCalled();
        expect(window.Craft.sendActionRequest.mock.calls[0][1]).toContain('/items');
        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(1);
    });
});
