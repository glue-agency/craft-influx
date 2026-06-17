import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LogApp from '../LogApp.vue';

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

const baseConfig = (over = {}) => ({
    log: { id: 1, linkHandle: 'news', trigger: 'cp', status: 'ok', startedAt: 'now', finishedAt: 'later', error: null, itemsSeen: 2, itemsCreated: 1, itemsUpdated: 1, itemsUnchanged: 0, itemsSkipped: 0, itemsDeleted: 0 },
    items: [
        { id: 1, action: 'created', matchValue: 'A', message: '', elementHtml: '<span>el</span>' },
        { id: 2, action: 'skipped', matchValue: 'B', message: '', elementHtml: null },
    ],
    streamUrl: '/logstream',
    itemUrlTemplate: '/items/__ID__',
    linkId: 7,
    isLive: false,
    ...over,
});

const mountApp = (over = {}) => mount(LogApp, {
    props: { config: baseConfig(over) },
    global: { mocks: { $t } },
});

describe('LogApp', () => {
    afterEach(() => { delete global.EventSource; });

    it('renders the items and the per-action filter counts', () => {
        const w = mountApp();

        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(2);
        const created = w.findAll('.influx-log-filters li').find((li) => li.text().includes('created'));
        expect(created.text()).toContain('1');
    });

    it('hides items whose action filter is toggled off', async () => {
        const w = mountApp();

        await w.find('#influx-log-filter-created').trigger('change');

        // Only the 'skipped' row remains.
        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(1);
    });

    it('does not open a stream for a finished log', () => {
        global.EventSource = FakeEventSource;
        FakeEventSource.last = null;
        mountApp({ isLive: false });
        expect(FakeEventSource.last).toBe(null);
    });

    it('streams live items + counters when running, and closes on done', async () => {
        global.EventSource = FakeEventSource;
        const w = mountApp({ isLive: true, log: { ...baseConfig().log, status: 'running' } });
        const es = FakeEventSource.last;

        expect(es.url).toContain('/logstream');

        es.emit('item', { id: 3, action: 'created', matchValue: 'C', message: '', elementHtml: null });
        es.emit('counters', { status: 'ok', itemsSeen: 3, itemsCreated: 2, itemsUpdated: 1, itemsUnchanged: 0, itemsSkipped: 0, itemsDeleted: 0 });
        await w.vm.$nextTick();

        expect(w.findAllComponents({ name: 'LogItem' }).length).toBe(3);

        es.emit('done', {});
        expect(es.closed).toBe(true);
    });
});
