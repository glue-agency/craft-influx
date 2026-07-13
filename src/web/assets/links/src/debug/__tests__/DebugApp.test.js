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

    it('surfaces a failed fetch as an error', async () => {
        window.Craft.sendActionRequest = vi.fn(() => Promise.reject(new Error('boom')));

        const w = mountApp();
        await flushPromises();

        expect(w.find('.influx-debug-error').exists()).toBe(true);
    });
});
