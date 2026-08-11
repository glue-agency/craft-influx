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
import MappingTab from '../MappingTab.vue';
import SearchableSelect from '../../../components/SearchableSelect.vue';

/**
 * The Match key control is only offered to a link that HAS a match key.
 *
 * A Global Set link, or an Entry link scoped to a Craft Single, identifies its
 * element from its criteria — so there is no match key, the server stops
 * requiring one, and this control has nothing to edit. The flag rides the
 * mappable-fields response rather than the per-element-type capability bundle,
 * because for entries it depends on the section: only the criteria can answer it,
 * and that response is what refetches when they change.
 *
 * Absent flag reads as true, the same tolerant default GeneralTab applies to its
 * capability flags, so an older payload keeps rendering the control.
 */

const bootstrapPayload = () => ({
    link: {
        handle: 'globals',
        name: 'Globals',
        elementType: 'craft\\elements\\GlobalSet',
        elementCriteria: { set: 'footer' },
        endpoint: '@data/globals.json',
        siteEndpoints: [],
        offset: {},
        mappings: {},
        match: {},
    },
    options: { elementTypes: [], processingActions: [], sites: [] },
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x', envSuggestions: [] },
});

// One field is enough to get past the "pick an element type" empty state, which
// otherwise hides the whole tab and would make every assertion below vacuous.
const mappable = (extra) => ({
    fields: [{ handle: 'cta', name: 'CTA', group: 'Content', mapping: { source: [{ type: 'select' }] } }],
    groups: [{ label: 'Content', fields: [{ handle: 'cta', name: 'CTA', mapping: { source: [{ type: 'select' }] } }] }],
    matchOptions: [{ label: null, kind: null, options: [{ value: '', label: '— select a field —' }] }],
    ...extra,
});

// The mappable tree is lazy-loaded, so load() alone leaves the tab on its
// "Loading mappable fields…" state — the refresh is what the tab components
// trigger when criteria change, and what fills ui.mappable here.
const loadStore = async (extra = {}) => {
    api.bootstrap.mockResolvedValue(bootstrapPayload());
    api.mappableFields.mockResolvedValue(mappable(extra));
    api.endpointTokenSuggestions.mockResolvedValue({ suggestions: [] });
    await store.load(1);
    await store.refreshMappableFields();
};

const mountTab = () => mount(MappingTab, {
    global: { mocks: { $t: (s) => s }, stubs: { ElementPicker: true } },
});

const matchSelect = (wrapper) => wrapper.findAllComponents(SearchableSelect)
    .filter((c) => c.props('placeholder') === 'Select an attribute…');

describe('MappingTab match key gate', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('offers the control when the link needs a match key', async () => {
        await loadStore({ requiresMatch: true });
        const wrapper = mountTab();

        expect(wrapper.text()).toContain('Match key');
        expect(matchSelect(wrapper)).toHaveLength(1);
    });

    it('hides the control entirely when the criteria name the element', async () => {
        await loadStore({ requiresMatch: false });
        const wrapper = mountTab();

        expect(wrapper.text()).not.toContain('Match key');
        expect(matchSelect(wrapper)).toHaveLength(0);
    });

    it('still shows the mapping groups when the match key is hidden', async () => {
        // Hiding the match key must not take the mappings with it.
        await loadStore({ requiresMatch: false });

        expect(mountTab().text()).toContain('Content');
    });

    it('falls back to showing the control when the flag is absent', async () => {
        await loadStore();

        expect(mountTab().text()).toContain('Match key');
    });
});
