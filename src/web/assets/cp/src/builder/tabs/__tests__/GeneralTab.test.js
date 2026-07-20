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
    configureActionUrls: vi.fn(),
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
        offset: { hour: { since: '-1 hour', queryParam: 'modified_since' } },
        siteEndpoints: [],
        processing: ['create', 'update'],
    },
    options: {
        elementTypes: [
            { value: 'craft\\elements\\Entry', label: 'Entry', criteria: ['section', 'type'], multiSite: true },
        ],
        sections: [],
        sectionEntryTypes: {},
        processingActions: [],
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

    it('keeps the sliding-window presets in state when its switch is turned off', async () => {
        const tab = mountTab().vm;

        tab.supportsOffset = false;
        await nextTick();

        expect(store.ui.supportsOffset).toBe(false);
        expect(store.link.offset).toEqual({ hour: { since: '-1 hour', queryParam: 'modified_since' } });
    });
});
