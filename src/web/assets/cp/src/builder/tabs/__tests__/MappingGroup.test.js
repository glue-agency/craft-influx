import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('../../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    fetchSample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    configureCsrf: vi.fn(),
}));

import * as api from '../../api.js';
import { store } from '../../store.js';
import MappingGroup from '../MappingGroup.vue';
import MappingGroupCard from '../../../components/MappingGroupCard.vue';

/**
 * The group header's "Clear" — one press wipes every field mapping in the
 * group, and the only clear affordance the mapping tab carries.
 *
 * Two regressions live here. The scoping one: only the group's own handles
 * may be dropped, in a single store write. And the collision with
 * MappingGroupCard's header, which is itself the collapse toggle on click AND
 * on keydown.enter/.space (both with .prevent) — without .stop on both
 * listeners a Clear press also collapses the card, and the header's .prevent
 * kills the button's native Enter → click before it fires at all.
 */

const group = {
    label: 'Content',
    fields: [
        { handle: 'title', name: 'Title', defaultType: 'text' },
        { handle: 'body', name: 'Body', defaultType: 'text' },
    ],
};

const bootstrapPayload = (mappings, meta) => ({
    link: {
        handle: 'articles',
        name: 'Articles',
        elementType: 'craft\\elements\\Entry',
        elementCriteria: {},
        endpoint: null,
        siteEndpoints: [],
        offset: {},
        mappings,
    },
    options: { elementTypes: [], sections: [], sectionEntryTypes: {}, processingActions: [], sites: [] },
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x', envSuggestions: [], ...meta },
});

const loadStore = async (mappings = {}, meta = {}) => {
    api.bootstrap.mockResolvedValue(bootstrapPayload(mappings, meta));
    api.mappableFields.mockResolvedValue({ fields: [], groups: [], matchOptions: [] });
    api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
    await store.load(1);
};

const mountGroup = () => mount(MappingGroup, {
    props: { group, nodeOptions: [] },
    global: { mocks: { $t: (s) => s }, stubs: { ElementPicker: true } },
});

const clearBtn = (wrapper) => wrapper.find('.clear-group');
const isCollapsed = (wrapper) => wrapper.find('.influx-mapping-group').classes().includes('collapsed');
const toggles = (wrapper) => wrapper.findComponent(MappingGroupCard).emitted('toggle');

describe('MappingGroup clear', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows the button only for an editable group with something saved', async () => {
        await loadStore();
        expect(clearBtn(mountGroup()).exists()).toBe(false);

        // Not "mapped" — a default and nothing else — but still something to
        // clear, so the button shows.
        await loadStore({ title: { default: 'Untitled' } });
        expect(clearBtn(mountGroup()).exists()).toBe(true);

        await loadStore({ title: { node: 'meta.title' } }, { readOnly: true });
        expect(clearBtn(mountGroup()).exists()).toBe(false);
    });

    it('stays hidden when only fields outside the group are mapped', async () => {
        await loadStore({ postDate: { node: 'meta.date' } });

        expect(clearBtn(mountGroup()).exists()).toBe(false);
    });

    it('drops exactly the group’s handles', async () => {
        await loadStore({
            title: { node: 'meta.title' },
            body: { default: 'x', options: { format: 'raw' } },
            postDate: { node: 'meta.date' },
        });
        const wrapper = mountGroup();

        await clearBtn(wrapper).trigger('click');

        expect(store.link.mappings).toEqual({ postDate: { node: 'meta.date' } });
    });

    it('does not collapse the card it sits in', async () => {
        await loadStore({ title: { node: 'meta.title' } });
        const wrapper = mountGroup();
        expect(isCollapsed(wrapper)).toBe(false);

        await clearBtn(wrapper).trigger('click');

        expect(isCollapsed(wrapper)).toBe(false);
        expect(toggles(wrapper)).toBeUndefined();
    });

    it('does not collapse the card on a keyboard press either', async () => {
        // jsdom won't synthesise the Enter → click itself, so what's asserted
        // is the half that breaks in the browser: the keydown never reaches
        // the header, so neither its toggle nor its .prevent runs.
        await loadStore({ title: { node: 'meta.title' } });
        const wrapper = mountGroup();

        await clearBtn(wrapper).trigger('keydown.enter');
        await clearBtn(wrapper).trigger('keydown.space');

        expect(isCollapsed(wrapper)).toBe(false);
        expect(toggles(wrapper)).toBeUndefined();
    });

    it('still lets the header itself collapse the card', async () => {
        // Guards the two specs above from passing vacuously on a card that
        // stopped toggling altogether.
        await loadStore({ title: { node: 'meta.title' } });
        const wrapper = mountGroup();

        await wrapper.find('.influx-mapping-group-header').trigger('click');

        expect(isCollapsed(wrapper)).toBe(true);
        expect(toggles(wrapper)).toEqual([[false]]);
    });
});

/**
 * The header's "auto" pill counts the rows Auto-match filled in — the same
 * transient store state the rows' own badges read, so the two can't disagree.
 */
describe('MappingGroup auto pill', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    const autoPill = (wrapper) => wrapper.find('.pill-auto');

    /**
     * Run a real Auto-match: the store only fills handles the field tree
     * offers, from suggestions the sample carries, so both have to be primed.
     * `postDate` is deliberately outside the group under test.
     */
    const autoMatchAll = async () => {
        api.mappableFields.mockResolvedValue({
            fields: [{ handle: 'title' }, { handle: 'body' }, { handle: 'postDate' }],
            groups: [],
            matchOptions: [],
        });
        await store.refreshMappableFields();

        api.fetchSample.mockResolvedValue({
            success: true,
            report: {
                itemCount: 1,
                flatNodes: [{ value: 'title', label: 'title' }, { value: 'postDate', label: 'postDate' }],
                mappingSuggestions: [
                    { field: 'title', type: 'PlainText', node: 'title' },
                    { field: 'postDate', type: 'PlainText', node: 'postDate' },
                ],
            },
        });
        await store.fetchSample();

        store.autoMatch();
    };

    it('stays out of the header until something was auto-matched', async () => {
        await loadStore({ title: { node: 'meta.title' } });

        expect(autoPill(mountGroup()).exists()).toBe(false);
    });

    it('counts the group’s own auto-matched rows, and no others', async () => {
        await loadStore();
        await autoMatchAll();

        // Both handles were filled, but only `title` is in this group.
        expect(store.ui.autoMatched).toEqual(['title', 'postDate']);
        expect(autoPill(mountGroup()).text()).toContain('1');
    });

    it('lets go of a row the user then picks for themselves', async () => {
        await loadStore();
        await autoMatchAll();
        const wrapper = mountGroup();
        expect(autoPill(wrapper).exists()).toBe(true);

        store.clearAutoMatch('title');
        await wrapper.vm.$nextTick();

        expect(autoPill(wrapper).exists()).toBe(false);
    });
});
