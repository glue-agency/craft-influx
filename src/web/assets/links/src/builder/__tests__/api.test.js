import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, bootstrap, configureActionUrls, configureCsrf, save } from '../api.js';

const jsonResponse = (body, { status = 200 } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => (body === null ? '' : JSON.stringify(body)),
});

beforeEach(() => {
    configureActionUrls({
        bootstrap: '/actions/influx/link-builder/bootstrap',
        save: '/actions/influx/link-builder/save',
    });
    configureCsrf({ name: 'CRAFT_CSRF_TOKEN', value: 'token-123' });
    globalThis.fetch = vi.fn();
});

describe('request envelope', () => {
    it('returns the parsed body on success', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: true, link: { handle: 'articles' } }));
        const result = await save({ handle: 'articles' });
        expect(result.link.handle).toBe('articles');
    });

    it('throws ApiError on a {success: false} body even with HTTP 200', async () => {
        fetch.mockResolvedValue(jsonResponse({
            success: false,
            message: "Couldn't save link.",
            errors: { handle: ['Handle is required.'] },
        }));

        const error = await save({}).catch(e => e);
        expect(error).toBeInstanceOf(ApiError);
        expect(error.message).toBe("Couldn't save link.");
        expect(error.errors).toEqual({ handle: ['Handle is required.'] });
        expect(error.status).toBe(200);
    });

    it('throws ApiError with the server message on non-2xx', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: false, message: 'Forbidden.' }, { status: 403 }));

        const error = await bootstrap(1).catch(e => e);
        expect(error).toBeInstanceOf(ApiError);
        expect(error.message).toBe('Forbidden.');
        expect(error.status).toBe(403);
        expect(error.errors).toEqual({});
    });

    it('falls back to a generic message when the body has none', async () => {
        fetch.mockResolvedValue(jsonResponse(null, { status: 500 }));

        const error = await bootstrap(1).catch(e => e);
        expect(error).toBeInstanceOf(ApiError);
        expect(error.message).toBe('Request failed (500)');
    });

    it('sends the CSRF token header on mutations', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: true, link: {} }));
        await save({ handle: 'x' });

        const [, init] = fetch.mock.calls[0];
        expect(init.headers['X-CSRF-Token']).toBe('token-123');
        expect(init.method).toBe('POST');
    });
});
