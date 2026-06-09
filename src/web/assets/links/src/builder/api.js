/**
 * Tiny fetch wrapper for the LinkBuilder SPA. Reads action URLs and the CSRF
 * token from the page's bootstrap meta (rendered server-side into the host
 * template) so we never have to hard-code CP paths into the JS.
 *
 * Every helper returns the parsed JSON body — errors throw with the raw
 * Response attached, so callers can branch on `e.response.status` when they
 * care to.
 */

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

    if (!res.ok) {
        const error = new Error(`Request failed (${res.status})`);
        error.response = res;
        error.body = body;
        throw error;
    }

    return body;
}

export async function bootstrap(handle) {
    const url = resolve('bootstrap', 'influx/link-builder/bootstrap');
    const qs = handle ? `handle=${encodeURIComponent(handle)}` : '';
    return request(withQuery(url, qs), { method: 'GET' });
}

export async function save(payload) {
    const url = resolve('save', 'influx/link-builder/save');
    return request(url, {
        method: 'POST',
        body: JSON.stringify(payload),
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

export async function sample(payload) {
    const url = resolve('sample', 'influx/link-builder/sample');
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
