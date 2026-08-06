import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';

vi.mock('../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    fetchSample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    configureCsrf: vi.fn(),
}));

import * as api from '../api.js';
import { store } from '../store.js';
import DetailsSidebar from '../DetailsSidebar.vue';

/**
 * The builder's details rail. It renders into Craft's pane rather than its own
 * subtree, so every spec here mounts it against a stand-in for that pane and
 * reads the teleported output out of the document.
 *
 * What it owes the screen: an honest one-line account of the sample, mapping
 * progress that matches the tree's own counting, and the two actions — of which
 * only Auto-match, the one that writes, goes where admin changes aren't allowed.
 */

const FIELDS = [
    { handle: 'title', name: 'Title', mapping: { source: [{ type: 'select' }] } },
    { handle: 'body', name: 'Body', mapping: { source: [{ type: 'select' }] } },
    // No source cell: its value comes from sub-mappings, so anything saved on the
    // row counts as mapped.
    { handle: 'specs', name: 'Specs', mapping: { extra: [{ type: 'subFields' }] } },
];

const bootstrapPayload = (mappings = {}, meta = {}, link = {}) => ({
    link: {
        handle: 'articles',
        name: 'Articles',
        elementType: 'craft\\elements\\Entry',
        elementCriteria: {},
        endpoint: 'https://example.test/feed',
        siteEndpoints: [],
        offset: {},
        mappings,
        ...link,
    },
    options: { elementTypes: [], sections: [], sectionEntryTypes: {}, processingActions: [], sites: [] },
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x', envSuggestions: [], ...meta },
});

const loadStore = async (mappings = {}, meta = {}, link = {}, fields = FIELDS) => {
    api.bootstrap.mockResolvedValue(bootstrapPayload(mappings, meta, link));
    api.mappableFields.mockResolvedValue({ fields, groups: [], matchOptions: [] });
    api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
    await store.load(1);
    // In the app the field tree arrives via a watcher on the element criteria;
    // awaiting the same action keeps these specs off that timing.
    await store.refreshMappableFields();
    await nextTick();
};

/** Put a report in the store the way a real fetch would. */
const primeSample = async (report) => {
    api.fetchSample.mockResolvedValue({ success: true, report });
    await store.fetchSample();
};

// Craft's pane, wrapping the slot the component teleports into.
const host = () => {
    const el = document.createElement('div');
    el.innerHTML = '<div id="details-container"><div id="details"><div class="details"><div data-influx-details-slot></div></div></div></div>';
    document.body.appendChild(el);

    return el;
};

const mountSidebar = async () => {
    const wrapper = mount(DetailsSidebar, { global: { mocks: { $t: (s, params) => (params ? `${s} ${JSON.stringify(params)}` : s) } } });
    await nextTick();

    return wrapper;
};

const rail = () => document.querySelector('#details-container');
const sections = () => document.querySelectorAll('.influx-details-section');
const statusDot = () => document.querySelector('.influx-details-section .status');
const statusText = () => document.querySelector('.influx-details-section .heading label span:last-child')?.textContent;
const buttons = () => Array.from(document.querySelectorAll('.influx-details-btn'));
const buttonFor = (icon) => buttons().find((btn) => btn.dataset.icon === icon);

describe('DetailsSidebar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    describe('mounting', () => {
        it('renders nothing at all when the pane has no slot', async () => {
            await loadStore();
            await mountSidebar();

            expect(sections()).toHaveLength(0);
            expect(buttons()).toHaveLength(0);
        });

        it('teleports its sections into the slot', async () => {
            host();
            await loadStore();
            await mountSidebar();

            expect(document.querySelector('[data-influx-details-slot]').closest('#details-container')).toBe(rail());
            expect(sections()).toHaveLength(2);
        });
    });

    describe('sample state', () => {
        beforeEach(() => {
            host();
        });

        it('reports a fetched sample by its item and node counts', async () => {
            await loadStore();
            await primeSample({ itemCount: 25, flatNodes: [{ value: 'id', label: 'id' }, { value: 'title', label: 'title' }] });
            await mountSidebar();

            expect(statusText()).toContain('Fetched');
            expect(statusText()).toContain('"items":25');
            expect(statusText()).toContain('"nodes":2');
            expect(statusDot().classList.contains('live')).toBe(true);
        });

        it('reports a partial report as incomplete, not as fetched', async () => {
            await loadStore();
            await primeSample({ itemCount: 0, flatNodes: [], warning: 'No list of items resolved.' });
            await mountSidebar();

            expect(statusText()).toContain('Incomplete');
            expect(statusDot().classList.contains('orange')).toBe(true);
        });

        it('reports a failed attempt', async () => {
            await loadStore();
            api.fetchSample.mockRejectedValue(new Error('404 Not Found'));
            await store.fetchSample();
            await mountSidebar();

            expect(statusText()).toBe('Fetch failed');
            expect(statusDot().classList.contains('red')).toBe(true);
        });

        it('offers a plain fetch before one has been run, and a refetch after', async () => {
            await loadStore();
            await mountSidebar();
            expect(buttonFor('download').textContent).toBe('Fetch sample');

            await primeSample({ itemCount: 1, flatNodes: [{ value: 'id', label: 'id' }] });
            await nextTick();

            expect(buttonFor('download').textContent).toBe('Refetch sample');
        });

        it('fetches on press', async () => {
            await loadStore();
            await mountSidebar();
            api.fetchSample.mockResolvedValue({ success: true, report: { itemCount: 1, flatNodes: [] } });

            buttonFor('download').click();

            expect(api.fetchSample).toHaveBeenCalled();
        });

        it('disables the fetch while there is no endpoint to hit', async () => {
            await loadStore({}, {}, { endpoint: '' });
            await mountSidebar();

            expect(buttonFor('download').disabled).toBe(true);
        });
    });

    describe('mapping progress', () => {
        beforeEach(() => {
            host();
        });

        it('counts a mapped node, and a cell-less row by its sub-mappings', async () => {
            await loadStore({
                title: { node: 'title' },
                specs: { fields: { col1: { node: 'specs.label' } } },
            });
            await mountSidebar();

            expect(document.querySelectorAll('.influx-details-section label')[1].textContent)
                .toContain('{"mapped":2,"total":3}');
        });

        it('warns about a saved node the sample does not carry', async () => {
            await loadStore({ title: { node: 'gone.away' } });
            await primeSample({ itemCount: 1, flatNodes: [{ value: 'id', label: 'id' }] });
            await mountSidebar();

            const warning = document.querySelector('.influx-details-warning');
            expect(warning.textContent).toContain('1 field is missing its source node');
            // Informative, not a way in: the tab nav is right there.
            expect(warning.tagName).toBe('SPAN');
        });

        it('flags nothing while no sample can say what is missing', async () => {
            await loadStore({ title: { node: 'gone.away' } });
            await mountSidebar();

            expect(document.querySelector('.influx-details-warning')).toBe(null);
        });

        it('drops the whole section when no field tree has loaded', async () => {
            await loadStore({}, {}, {}, []);
            await mountSidebar();

            expect(sections()).toHaveLength(1);
        });

        it('auto-matches on press, and refuses before a sample exists', async () => {
            await loadStore();
            await mountSidebar();
            expect(buttonFor('wand').disabled).toBe(true);

            await primeSample({
                itemCount: 1,
                flatNodes: [{ value: 'title', label: 'title' }],
                mappingSuggestions: [{ field: 'title', type: 'PlainText', node: 'title' }],
            });
            await nextTick();

            buttonFor('wand').click();

            expect(store.link.mappings.title).toEqual({ node: 'title' });
            expect(store.ui.autoMatched).toEqual(['title']);
        });
    });

    // A read-only environment can't save the config, so Auto-match goes. Fetch
    // stays: it writes nothing, and the sample is what makes the mappings
    // readable at all.
    describe('read-only', () => {
        it('keeps the fetch button and drops auto-match', async () => {
            host();
            await loadStore({}, { readOnly: true });
            await primeSample({
                itemCount: 3,
                flatNodes: [{ value: 'id', label: 'id' }],
                mappingSuggestions: [{ field: 'title', type: 'PlainText', node: 'id' }],
            });
            await mountSidebar();

            expect(statusText()).toContain('Fetched');
            expect(buttonFor('download')).toBeTruthy();
            expect(buttonFor('wand')).toBeUndefined();
        });

        it('refetches on press', async () => {
            host();
            await loadStore({}, { readOnly: true });
            await mountSidebar();
            api.fetchSample.mockClear();
            api.fetchSample.mockResolvedValue({ success: true, report: { itemCount: 1, flatNodes: [] } });

            buttonFor('download').click();
            await nextTick();

            expect(api.fetchSample).toHaveBeenCalled();
        });
    });
});
