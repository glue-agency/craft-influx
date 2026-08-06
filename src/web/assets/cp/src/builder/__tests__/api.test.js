import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, bootstrap, configureCsrf, defaultOptions, deleteLink, renderElementSelect, renderIconPicker, save } from '../api.js';

const jsonResponse = (body, { status = 200 } = {}) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => (body === null ? '' : JSON.stringify(body)),
});

beforeEach(() => {
    configureCsrf({ name: 'CRAFT_CSRF_TOKEN', value: 'token-123' });
    globalThis.fetch = vi.fn();
});

describe('request envelope', () => {
    it('returns the parsed body on success', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: true, link: { handle: 'articles' } }));
        const result = await save({ handle: 'articles' });
        expect(result.link.handle).toBe('articles');
    });

    it('deleteLink POSTs the uid to the delete action', async () => {
        fetch.mockResolvedValue(jsonResponse({ success: true, message: 'Link deleted.' }));
        await deleteLink('abc-123');

        const [url, init] = fetch.mock.calls[0];
        expect(url).toContain('influx/links/delete');
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body)).toEqual({ uid: 'abc-123' });
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

describe('render-element-select query', () => {
    it('carries every picked id, plus the field the picker is shaped after', async () => {
        fetch.mockResolvedValue(jsonResponse({ html: '', jsSettings: {} }));
        await renderElementSelect('craft\\elements\\Entry', ['12', '34'], 'relatedArticles');

        const [url] = fetch.mock.calls[0];
        expect(url).toContain('ids%5B%5D=12&ids%5B%5D=34');
        expect(url).toContain('fieldHandle=relatedArticles');
    });

    it('names no field when the row is a native one', async () => {
        fetch.mockResolvedValue(jsonResponse({ html: '', jsSettings: {} }));
        await renderElementSelect('craft\\elements\\User', [], null);

        expect(fetch.mock.calls[0][0]).not.toContain('fieldHandle');
    });
});

describe('default-options query', () => {
    it('names the field whose strategy answers for the list', async () => {
        fetch.mockResolvedValue(jsonResponse({ options: [{ value: 'BE', label: 'Belgium' }] }));
        const payload = await defaultOptions('test_country');

        const [url, init] = fetch.mock.calls[0];
        expect(url).toContain('fieldHandle=test_country');
        expect(init.method).toBe('GET');
        expect(payload.options).toEqual([{ value: 'BE', label: 'Belgium' }]);
    });
});

describe('render-icon-picker query', () => {
    it('names the field shaping the picker, and seeds the current icon', async () => {
        fetch.mockResolvedValue(jsonResponse({ html: '', jsSettings: { id: 'x', freeOnly: true } }));
        await renderIconPicker('test_icon', 'user');

        const [url] = fetch.mock.calls[0];
        expect(url).toContain('fieldHandle=test_icon');
        expect(url).toContain('value=user');
    });

    it('sends no value for an unset default', async () => {
        fetch.mockResolvedValue(jsonResponse({ html: '', jsSettings: { id: 'x', freeOnly: true } }));
        await renderIconPicker('test_icon', null);

        expect(fetch.mock.calls[0][0]).not.toContain('value=');
    });
});
