import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../api.js', () => ({
    bootstrap: vi.fn(),
    save: vi.fn(),
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
    meta: { isNew: false, csrfTokenName: 'CRAFT_CSRF_TOKEN', csrfToken: 'x' },
});

const apiError = (message, errors = {}) => {
    const error = new Error(message);
    error.errors = errors;
    return error;
};

describe('store', () => {
    beforeEach(async () => {
        vi.clearAllMocks();
        api.bootstrap.mockResolvedValue(bootstrapPayload());
        await store.load('articles');
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
        await store.load('missing');
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
});
