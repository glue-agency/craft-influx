import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

vi.mock('../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
    deleteLink: vi.fn(),
    sample: vi.fn(),
    mappableFields: vi.fn(),
    renderElementSelect: vi.fn(),
    endpointTokenSuggestions: vi.fn(),
    configureCsrf: vi.fn(),
    configureActionUrls: vi.fn(),
}));

import * as api from '../api.js';
import { store } from '../store.js';

const bootstrapPayload = () => ({
    link: {
        handle: 'articles',
        name: 'Articles',
        elementType: 'craft\\elements\\Entry',
        mappings: { title: { node: 'name' } },
    },
    options: {},
    meta: { isNew: false, uid: 'link-uid-1', csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x' },
});

const apiError = (message, errors = {}) => {
    const error = new Error(message);
    error.errors = errors;
    return error;
};

describe('store', () => {
    beforeEach(async () => {
        // Fake timers keep the sample auto-fetcher's debounce inert unless
        // a test advances time explicitly.
        vi.useFakeTimers();
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue(bootstrapPayload());
        await store.load(1);
        vi.clearAllTimers();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('hydrates from bootstrap and reads clean', () => {
        expect(store.ui.link.handle).toBe('articles');
        expect(store.ui.loadError).toBe(null);
        expect(store.isDirty.value).toBe(false);
    });

    it('derives dirty from edits — and reverting reads clean again', () => {
        store.link.name = 'Changed';
        expect(store.isDirty.value).toBe(true);

        store.link.name = 'Articles';
        expect(store.isDirty.value).toBe(false);
    });

    it('stores the ApiError message as loadError when bootstrap fails', async () => {
        api.bootstrap.mockRejectedValue(apiError('Link not found.'));
        await store.load(999);
        expect(store.ui.loadError).toBe('Link not found.');
    });

    it('keeps validation errors and stays dirty on a failed save', async () => {
        store.link.name = 'Changed';
        api.save.mockRejectedValue(apiError("Couldn't save link.", { handle: ['Handle is required.'] }));

        const result = await store.save({ continueEditing: true });

        expect(result.success).toBe(false);
        expect(store.ui.errors).toEqual({ handle: ['Handle is required.'] });
        expect(store.isDirty.value).toBe(true);
    });

    it('adopts the canonical link and a fresh snapshot on a successful save', async () => {
        store.link.name = 'Changed';
        api.save.mockResolvedValue({
            success: true,
            link: { ...bootstrapPayload().link, name: 'Changed (normalized)' },
        });

        const result = await store.save({ continueEditing: true });

        expect(result.success).toBe(true);
        expect(store.ui.link.name).toBe('Changed (normalized)');
        expect(store.ui.errors).toEqual({});
        expect(store.isDirty.value).toBe(false);
    });

    it('deletes by the bootstrapped uid and reports success', async () => {
        api.deleteLink.mockResolvedValue({ success: true, message: 'Link deleted.' });

        const result = await store.deleteLink();

        expect(api.deleteLink).toHaveBeenCalledWith('link-uid-1');
        expect(result.success).toBe(true);
    });

    it('reports failure and stays put when the delete request fails', async () => {
        api.deleteLink.mockRejectedValue(apiError('Link not found.'));

        const result = await store.deleteLink();

        expect(result.success).toBe(false);
        // The link is still loaded — nothing was torn down client-side.
        expect(store.link.handle).toBe('articles');
    });

    it('stores the ApiError message as sampleError when the sample fetch fails', async () => {
        api.sample.mockRejectedValue(apiError('Root node does not resolve to an array.'));
        await store.fetchSample();
        expect(store.ui.sampleError).toBe('Root node does not resolve to an array.');
        expect(store.ui.sampling).toBe(false);
    });

    it('stashes the report on a successful sample fetch', async () => {
        api.sample.mockResolvedValue({ success: true, report: { flatNodes: [{ value: 'id', label: 'id' }] } });
        await store.fetchSample();
        expect(store.ui.sample.flatNodes).toHaveLength(1);
        expect(store.ui.sampleError).toBe(null);
    });

    it('auto-fetches the sample when the endpoint field settles (blur)', async () => {
        api.sample.mockResolvedValue({ success: true, report: { flatNodes: [] } });

        store.link.endpoint = 'https://example.test/auto';
        await nextTick();
        expect(api.sample).not.toHaveBeenCalled(); // typing alone never fetches

        await store.evaluateSample();
        expect(api.sample).toHaveBeenCalledWith(
            expect.objectContaining({ endpoint: 'https://example.test/auto' }),
        );

        // Blur without changes is free — the key matches what was fetched.
        await store.evaluateSample();
        expect(api.sample).toHaveBeenCalledTimes(1);
    });

    it('re-fetches the sample when the root node changes', async () => {
        api.sample.mockResolvedValue({ success: true, report: { flatNodes: [] } });

        store.link.endpoint = 'https://example.test/root-change';
        await store.evaluateSample();

        store.link.rootNode = 'data.items';
        await nextTick(); // root-node watcher evaluates immediately

        expect(api.sample).toHaveBeenCalledTimes(2);
        expect(api.sample).toHaveBeenLastCalledWith(
            expect.objectContaining({ rootNode: 'data.items' }),
        );
    });

    it('samples against the first filled site endpoint in site-specific mode', async () => {
        api.sample.mockResolvedValue({ success: true, report: { flatNodes: [] } });

        store.link.endpoint = 'https://example.test/base';
        store.link.siteEndpoints = [
            { site: 'en', endpoint: '' },
            { site: 'nl', endpoint: 'https://example.test/nl' },
        ];
        store.setSiteEndpointsMode(true);
        await store.evaluateSample();

        expect(api.sample).toHaveBeenCalledWith(
            expect.objectContaining({ endpoint: 'https://example.test/nl' }),
        );
    });

    it('blocks saving in site-specific mode without a single site endpoint', async () => {
        store.link.siteEndpoints = [{ site: 'en', endpoint: '   ' }];
        store.setSiteEndpointsMode(true);

        const result = await store.save({ continueEditing: true });

        expect(result.success).toBe(false);
        expect(store.ui.errors.siteEndpoints).toHaveLength(1);
        expect(api.save).not.toHaveBeenCalled();
    });
});
