import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DebugApp from '../DebugApp.vue';

/**
 * Controllable EventSource stand-in: happy-dom has no SSE, and we want to
 * drive meta/item/done frames synchronously and assert lifecycle (close on
 * done / re-inspect / unmount).
 */
class FakeEventSource {
    constructor(url) {
        this.url = url;
        this.listeners = {};
        this.closed = false;
        FakeEventSource.last = this;
    }

    addEventListener(type, cb) {
        (this.listeners[type] = this.listeners[type] || []).push(cb);
    }

    emit(type, data) {
        (this.listeners[type] || []).forEach((cb) => cb({ data: JSON.stringify(data) }));
    }

    close() {
        this.closed = true;
    }
}

const $t = (s, p) => (p ? String(s).replace(/\{(\w+)\}/g, (m, k) => (k in p ? p[k] : m)) : s);

const mountApp = (config = {}) => mount(DebugApp, {
    props: { config: { streamUrl: '/stream', sites: [], offsetHandles: [], limit: 25, ...config } },
    global: { mocks: { $t } },
});

describe('DebugApp', () => {
    beforeEach(() => { global.EventSource = FakeEventSource; });
    afterEach(() => { delete global.EventSource; });

    it('opens a stream on mount and renders meta + items', async () => {
        const w = mountApp();
        const es = FakeEventSource.last;

        expect(es.url).toContain('/stream');

        es.emit('meta', { url: 'https://x', itemsOnPage: 2, limit: 25 });
        es.emit('item', { action: 'would-update', mappings: [], raw: {} });
        await w.vm.$nextTick();

        expect(w.findAllComponents({ name: 'DebugItem' }).length).toBe(1);

        es.emit('done', {});
        expect(es.closed).toBe(true);
    });

    it('re-inspects in place: closes the old stream and clears items', async () => {
        const w = mountApp();
        const es1 = FakeEventSource.last;
        es1.emit('item', { action: 'x', mappings: [], raw: {} });
        await w.vm.$nextTick();
        expect(w.findAllComponents({ name: 'DebugItem' }).length).toBe(1);

        await w.find('form').trigger('submit');

        expect(es1.closed).toBe(true);
        expect(FakeEventSource.last).not.toBe(es1);
        expect(w.findAllComponents({ name: 'DebugItem' }).length).toBe(0);
    });

    it('closes the stream on unmount', () => {
        const w = mountApp();
        const es = FakeEventSource.last;
        w.unmount();
        expect(es.closed).toBe(true);
    });
});
