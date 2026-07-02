import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import DebugApp from '../DebugApp.vue';

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const mountApp = (config = {}) => mount(DebugApp, {
    props: { config: { inspectUrl: '/inspect', sites: [], offsetHandles: [], limit: 25, ...config } },
    global: { mocks: { $t } },
});

describe('DebugApp', () => {
    afterEach(() => {
        window.Craft.sendActionRequest = () => Promise.resolve({ data: {} });
    });

    it('inspects on mount and renders meta + items from one fetch', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.resolve({
            data: {
                meta: { url: 'https://x', itemsOnPage: 2, limit: 25 },
                items: [{ action: 'would-update', mappings: [], raw: {} }],
            },
        }));

        const w = mountApp();
        await flushPromises();

        expect(window.Craft.sendActionRequest).toHaveBeenCalled();
        expect(window.Craft.sendActionRequest.mock.calls[0][1]).toContain('/inspect');
        expect(w.findAllComponents({ name: 'DebugItem' }).length).toBe(1);
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

    it('remounts item rows on re-inspect so per-row state cannot go stale', async () => {
        const payload = (action) => Promise.resolve({
            data: { meta: { url: 'x' }, items: [{ action, mappings: [], raw: {} }] },
        });
        window.Craft.sendActionRequest = vi.fn(() => payload('would-update'));

        const w = mountApp();
        await flushPromises();
        const firstRow = w.findComponent({ name: 'DebugItem' });

        window.Craft.sendActionRequest = vi.fn(() => payload('would-create'));
        await w.find('form').trigger('submit');
        await flushPromises();

        // A fresh inspect must produce a fresh component at the same position
        // (keys include the inspect run), not patch the old one in place.
        const secondRow = w.findComponent({ name: 'DebugItem' });
        expect(secondRow.props('row').action).toBe('would-create');
        expect(secondRow.vm).not.toBe(firstRow.vm);
    });

    it('surfaces a failed fetch as a feed error', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.reject(new Error('boom')));

        const w = mountApp();
        await flushPromises();

        expect(w.find('.influx-feed-error').exists()).toBe(true);
    });
});
