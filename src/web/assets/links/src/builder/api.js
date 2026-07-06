/**
 * Tiny fetch wrapper for the LinkBuilder SPA. Reads action URLs and the CSRF
 * token from the page's bootstrap meta (rendered server-side into the host
 * template) so we never have to hard-code CP paths into the JS.
 *
 * Every helper returns the parsed JSON body on success. EVERY failure —
 * non-2xx transport status or a `{success: false}` body — throws one
 * ApiError, so callers never branch on response shapes.
 */

/**
 * The single failure type every api.* helper throws. Normalizes the
 * server's `{success: false, message, errors?}` envelope and raw transport
 * failures into one shape:
 *
 *   e.message — human-readable summary (server message when available)
 *   e.errors  — attribute → message[] validation errors (empty when none)
 *   e.status  — HTTP status (0 when the request never completed)
 *   e.body    — the raw parsed body, for anything exotic
 */
export class ApiError extends Error {
    constructor(message, { status = 0, errors = {}, body = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors;
        this.body = body;
    }
}

let actionUrls = null;
let csrfTokenName = null;
let csrfToken = null;

/**
 * Pulled from the bootstrap response's `meta` block by the store after the
 * initial fetch. The store calls this once before any mutation; before that,
 * only the bootstrap GET (which goes via the static path) is callable.
 */
export function configureCsrf({ name, value }) {
    csrfTokenName = name;
    csrfToken = value;
}

export function configureActionUrls(map) {
    actionUrls = { ...map };
}

/**
 * Resolve an action URL by its registered key. Falls back to a Craft-style
 * action path if the key isn't pre-registered, which keeps callers from
 * needing to thread URLs through the bootstrap response for one-off calls.
 */
function resolve(key, fallbackAction) {
    if (actionUrls && actionUrls[key]) return actionUrls[key];
    if (fallbackAction) return Craft.getActionUrl(fallbackAction);
    throw new Error(`No URL registered for action '${key}' and no fallback provided.`);
}

/**
 * Append a query string to a URL, choosing `?` or `&` based on whether the
 * base URL already has a query. Craft's `Craft.getActionUrl(...)` returns
 * something like `/index.php?p=admin/actions/...&site=nl` so a naive
 * `${url}?${qs}` produces a malformed double-`?`.
 */
function withQuery(url, qs) {
    if (!qs) return url;
    const sep = url.includes('?') ? '&' : '?';
    return `${url}${sep}${qs}`;
}

async function request(url, init) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        ...init,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(init.body ? { 'Content-Type': 'application/json' } : {}),
            ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
            ...(init.headers || {}),
        },
    });

    let body = null;
    const text = await res.text();
    if (text) {
        try {
            body = JSON.parse(text);
        } catch (e) {
            // Non-JSON response — surface as text for the caller.
            body = { raw: text };
        }
    }

    if (!res.ok || body?.success === false) {
        throw new ApiError(
            body?.message || body?.error || `Request failed (${res.status})`,
            { status: res.status, errors: body?.errors || {}, body },
        );
    }

    return body;
}

export async function bootstrap(id) {
    const url = resolve('bootstrap', 'influx/link-builder/bootstrap');
    const qs = id ? `id=${encodeURIComponent(id)}` : '';
    return request(withQuery(url, qs), { method: 'GET' });
}

export async function save(payload) {
    const url = resolve('save', 'influx/link-builder/save');
    return request(url, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export async function deleteLink(uid) {
    const url = resolve('delete', 'influx/links/delete');
    return request(url, {
        method: 'POST',
        body: JSON.stringify({ uid }),
    });
}

export async function mappableFields(elementType, criteria) {
    const url = resolve('mappableFields', 'influx/link-builder/mappable-fields');
    const params = new URLSearchParams({ elementType });
    for (const [k, v] of Object.entries(criteria || {})) {
        if (v != null && v !== '') params.set(`criteria[${k}]`, v);
    }
    return request(withQuery(url, params.toString()), { method: 'GET' });
}

export async function fetchSample(payload) {
    const url = resolve('fetchSample', 'influx/link-builder/fetch-sample');
    return request(url, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export async function renderElementSelect(elementType, ids) {
    const url = resolve('renderElementSelect', 'influx/link-builder/render-element-select');
    const params = new URLSearchParams({ elementType });
    for (const id of (ids || [])) {
        if (id != null && id !== '') params.append('ids[]', String(id));
    }
    return request(withQuery(url, params.toString()), { method: 'GET' });
}

export async function endpointTokenSuggestions(elementType, criteria) {
    const url = resolve('endpointTokenSuggestions', 'influx/link-builder/endpoint-token-suggestions');
    const params = new URLSearchParams({ elementType });
    for (const [k, v] of Object.entries(criteria || {})) {
        if (v != null && v !== '') params.set(`criteria[${k}]`, v);
    }
    return request(withQuery(url, params.toString()), { method: 'GET' });
}
