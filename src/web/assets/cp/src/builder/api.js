/**
 * Tiny fetch wrapper for the LinkBuilder SPA. Builds action URLs through
 * `Craft.getActionUrl()` and reads the CSRF token from the page's bootstrap
 * meta (rendered server-side into the host template), so no CP path is ever
 * hard-coded into the JS.
 *
 * Every helper returns the parsed JSON body on success. EVERY failure —
 * non-2xx transport status or a `{success: false}` body — throws one
 * ApiError, so callers never branch on response shapes.
 */

/**
 * The single failure type every api.* helper throws. Normalizes the server's
 * error envelope (`{success: false, message}` plus either `type` for an uncaught
 * exception or `errors` for a validation failure — see types.js's ErrorEnvelope)
 * and raw transport failures into one shape:
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
            body?.message || `Request failed (${res.status})`,
            { status: res.status, errors: body?.errors || {}, body },
        );
    }

    return body;
}

export async function bootstrap(id, duplicateOf = null) {
    const url = Craft.getActionUrl('influx/link-builder/bootstrap');
    const params = new URLSearchParams();
    if (id) params.set('id', id);
    if (duplicateOf) params.set('duplicateOf', duplicateOf);
    return request(withQuery(url, params.toString()), { method: 'GET' });
}

export async function save(payload) {
    const url = Craft.getActionUrl('influx/link-builder/save');
    return request(url, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

export async function deleteLink(uid) {
    const url = Craft.getActionUrl('influx/links/delete');
    return request(url, {
        method: 'POST',
        body: JSON.stringify({ uid }),
    });
}

export async function mappableFields(elementType, criteria) {
    const url = Craft.getActionUrl('influx/link-builder/mappable-fields');
    const params = new URLSearchParams({ elementType });
    for (const [k, v] of Object.entries(criteria || {})) {
        if (v != null && v !== '') params.set(`criteria[${k}]`, v);
    }
    return request(withQuery(url, params.toString()), { method: 'GET' });
}

export async function fetchSample(payload) {
    const url = Craft.getActionUrl('influx/link-builder/fetch-sample');
    return request(url, {
        method: 'POST',
        body: JSON.stringify(payload),
    });
}

// `fieldHandle` names the custom field the default is picked for, and shapes
// the picker after that field's own sources and relation limit. Only custom
// field rows send it — a native row would risk colliding with a same-handled
// custom field (see MappingRow).
export async function renderElementSelect(elementType, ids, fieldHandle = null) {
    const url = Craft.getActionUrl('influx/link-builder/render-element-select');
    const params = new URLSearchParams({ elementType });
    for (const id of (ids || [])) {
        if (id != null && id !== '') params.append('ids[]', String(id));
    }
    if (fieldHandle) params.set('fieldHandle', String(fieldHandle));
    return request(withQuery(url, params.toString()), { method: 'GET' });
}

export async function endpointTokenSuggestions(elementType, criteria) {
    const url = Craft.getActionUrl('influx/link-builder/endpoint-token-suggestions');
    const params = new URLSearchParams({ elementType });
    for (const [k, v] of Object.entries(criteria || {})) {
        if (v != null && v !== '') params.set(`criteria[${k}]`, v);
    }
    return request(withQuery(url, params.toString()), { method: 'GET' });
}
