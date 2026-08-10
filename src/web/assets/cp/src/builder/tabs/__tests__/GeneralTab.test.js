import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
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
import GeneralTab from '../GeneralTab.vue';

// A link with both toggle-gated features populated, plus the options bundle
// GeneralTab's template/computeds read so a mount renders without throwing.
const bootstrapPayload = () => ({
    link: {
        handle: 'articles',
        name: 'Articles',
        elementType: 'craft\\elements\\Entry',
        elementCriteria: {},
        endpoint: 'https://example.test/feed.json',
        itemEndpoint: 'https://example.test/items/{id}',
        offset: { hour: { queryParam: 'modified_since', value: "{{ now|date_modify('-1 hour')|date('c', 'UTC') }}" } },
        siteEndpoints: [],
        processing: ['create', 'update'],
    },
    options: {
        // Mirrors LinkBuilderOptionsPresenter::elementTypeOptions(): each target
        // ships its own criteria form plus its three capability flags. Entry's
        // entry-type node is keyed on the picked section, the cascade the
        // `dependsOn` / `optionsBy` pair exists for.
        elementTypes: [
            {
                value: 'craft\\elements\\Entry',
                label: 'Entry',
                criteria: ['section', 'type'],
                criteriaSchema: [
                    {
                        type: 'select',
                        handle: 'section',
                        label: 'Section',
                        options: [{ value: '', label: '— select —' }, { value: 'news', label: 'News' }],
                    },
                    {
                        type: 'select',
                        handle: 'type',
                        label: 'Entry Type',
                        dependsOn: 'section',
                        optionsBy: {
                            news: [{ value: '', label: '— select —' }, { value: 'article', label: 'Article' }],
                        },
                    },
                ],
                multiSite: true,
                creating: true,
                sweeping: true,
            },
            { value: 'craft\\elements\\User', label: 'User', criteria: [], criteriaSchema: [], multiSite: false, creating: true, sweeping: false },
            {
                value: 'craft\\elements\\GlobalSet',
                label: 'Global Set',
                criteria: ['set'],
                criteriaSchema: [{ type: 'select', handle: 'set', label: 'Global Set', options: [] }],
                multiSite: true,
                creating: false,
                sweeping: false,
            },
        ],
        // Mirrors processingActionOptions(): the writes carry missingPolicy
        // false, the four sweep policies true, forSite marks the two of those
        // that act per site, and `creating` marks the one a non-creating target
        // doesn't get.
        processingActions: [
            { value: 'create', label: 'Create', note: '', creating: true, missingPolicy: false, forSite: false },
            { value: 'update', label: 'Update', note: '', creating: false, missingPolicy: false, forSite: false },
            { value: 'disable', label: 'Disable globally', note: '', creating: false, missingPolicy: true, forSite: false },
            { value: 'delete', label: 'Delete globally', note: '', creating: false, missingPolicy: true, forSite: false },
            { value: 'disable-for-site', label: 'Disable for site', note: '', creating: false, missingPolicy: true, forSite: true },
            { value: 'delete-for-site', label: 'Delete for site', note: '', creating: false, missingPolicy: true, forSite: true },
        ],
        sites: [],
    },
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x', envSuggestions: [] },
});

const mountTab = () => mount(GeneralTab, {
    global: {
        mocks: { $t: (s) => s },
        stubs: {
            TokenizedInput: true,
            OffsetPresetsTable: true,
            SiteEndpointsTable: true,
            LightSwitch: true,
            FieldErrors: true,
        },
    },
});

describe('GeneralTab feature toggles', () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue(bootstrapPayload());
        api.mappableFields.mockResolvedValue({ fields: [], groups: [], matchOptions: [] });
        api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
        await store.load(1);
        vi.clearAllTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('derives the switches from the loaded link (via the store)', () => {
        const tab = mountTab().vm;
        expect(tab.supportsItemEndpoint).toBe(true);
        expect(tab.supportsOffset).toBe(true);
    });

    it('records the switch state in the store without clearing the value', async () => {
        const tab = mountTab().vm;

        tab.supportsItemEndpoint = false;
        await nextTick();

        // The switch state lands in the store (so save() can strip the payload
        // and dirty-tracking sees the flip)...
        expect(store.ui.supportsItemEndpoint).toBe(false);
        // ...but the value stays in state — flipping off just hides the editor.
        expect(store.link.itemEndpoint).toBe('https://example.test/items/{id}');
    });

    it('keeps the partial-import presets in state when its switch is turned off', async () => {
        const tab = mountTab().vm;

        tab.supportsOffset = false;
        await nextTick();

        expect(store.ui.supportsOffset).toBe(false);
        expect(store.link.offset).toEqual({ hour: { queryParam: 'modified_since', value: "{{ now|date_modify('-1 hour')|date('c', 'UTC') }}" } });
    });
});

describe('GeneralTab processing actions', () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue(bootstrapPayload());
        api.mappableFields.mockResolvedValue({ fields: [], groups: [], matchOptions: [] });
        api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
        await store.load(1);
        vi.clearAllTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('offers the writes plus the GLOBAL policies for a single-endpoint link', () => {
        // The per-site pair is meaningless without site endpoints — and the save
        // would rewrite it to these two anyway.
        const tab = mountTab().vm;

        expect(tab.supportsSweeping).toBe(true);
        expect(tab.processingActions.map((o) => o.value)).toEqual(['create', 'update', 'disable', 'delete']);
    });

    it('swaps in the per-site policies once site-specific endpoints are on', async () => {
        const wrapper = mountTab();

        store.setSiteEndpointsMode(true);
        await nextTick();

        expect(wrapper.vm.processingActions.map((o) => o.value))
            .toEqual(['create', 'update', 'disable-for-site', 'delete-for-site']);
        expect(wrapper.findAll('.checkbox-group input[type="checkbox"]')).toHaveLength(4);

        store.setSiteEndpointsMode(false);
    });

    it('hides the missing-element policies for an element type that cannot be swept', async () => {
        const wrapper = mountTab();
        const tab = wrapper.vm;

        store.link.elementType = 'craft\\elements\\User';
        await nextTick();

        expect(tab.supportsSweeping).toBe(false);
        // Only the writes are left — a User link's run would skip the rest.
        expect(tab.processingActions.map((o) => o.value)).toEqual(['create', 'update']);
        expect(wrapper.findAll('.checkbox-group input[type="checkbox"]')).toHaveLength(2);
    });

    it('defaults to sweepable while no element type is resolved', async () => {
        const tab = mountTab().vm;

        store.link.elementType = 'vendor\\elements\\Widget';
        await nextTick();

        expect(tab.supportsSweeping).toBe(true);
        expect(tab.processingActions).toHaveLength(4);
    });

    it('drops create for an element type that cannot be created', async () => {
        const wrapper = mountTab();
        const tab = wrapper.vm;

        store.link.elementType = 'craft\\elements\\GlobalSet';
        await nextTick();

        expect(tab.supportsCreating).toBe(false);
        // A global set is declared in project config, so update is all that's left.
        expect(tab.processingActions.map((o) => o.value)).toEqual(['update']);
        expect(wrapper.findAll('.checkbox-group input[type="checkbox"]')).toHaveLength(1);
    });

    it('defaults to creatable while no element type is resolved', async () => {
        const tab = mountTab().vm;

        store.link.elementType = 'vendor\\elements\\Widget';
        await nextTick();

        expect(tab.supportsCreating).toBe(true);
    });
});

describe('GeneralTab target criteria', () => {
    beforeEach(async () => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue(bootstrapPayload());
        api.mappableFields.mockResolvedValue({ fields: [], groups: [], matchOptions: [] });
        api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
        await store.load(1);
        vi.clearAllTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('renders the selected target’s own criteria form', () => {
        const wrapper = mountTab();

        // Two selects, from Entry's schema alone — nothing in the tab names a
        // criteria key.
        expect(wrapper.vm.criteriaSchema).toHaveLength(2);
        expect(wrapper.findAll('.influx-schema-form select')).toHaveLength(2);
    });

    it('renders no criteria form for an element type with nothing to scope on', async () => {
        const wrapper = mountTab();

        store.link.elementType = 'craft\\elements\\User';
        await nextTick();

        expect(wrapper.vm.criteriaSchema).toEqual([]);
        expect(wrapper.find('.influx-schema-form').exists()).toBe(false);
    });

    it('drops a cleared dropdown rather than storing an empty criterion', () => {
        const tab = mountTab().vm;

        // '' from the select's sentinel row, null from a parent clearing its
        // dependent — Link::criterion() reads either as "not set".
        tab.onCriteriaChange({ section: 'news', type: '' });
        expect(store.link.elementCriteria).toEqual({ section: 'news' });

        tab.onCriteriaChange({ section: 'news', type: null });
        expect(store.link.elementCriteria).toEqual({ section: 'news' });
    });

    it('narrows the dependent dropdown to the picked parent’s list', async () => {
        const wrapper = mountTab();

        store.link.elementCriteria = { section: 'news', type: null };
        await nextTick();

        const [, entryType] = wrapper.findAll('.influx-schema-form select');

        expect(entryType.findAll('option').map((o) => o.attributes('value')))
            .toEqual(['', 'article']);
    });

    it('offers a dependent dropdown nothing until its parent is picked', () => {
        const wrapper = mountTab();
        const [, entryType] = wrapper.findAll('.influx-schema-form select');

        expect(entryType.findAll('option')).toHaveLength(0);
    });
});
