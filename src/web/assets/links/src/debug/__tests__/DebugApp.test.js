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

    it('surfaces a failed fetch as a feed error', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.reject(new Error('boom')));

        const w = mountApp();
        await flushPromises();

        expect(w.find('.influx-feed-error').exists()).toBe(true);
    });
});
