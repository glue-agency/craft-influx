import { reactive, readonly } from 'vue';
import * as api from './api.js';

/**
 * Minimal reactive store for the LinkBuilder SPA. Vue's `reactive()` + a
 * handful of named actions is enough surface area — no Pinia / Vuex layer
 * needed for a single-screen builder.
 *
 * The store exposes:
 *   - `state`        a read-only proxy of the entire reactive root
 *   - `link`         convenience getter for the editable link payload
 *   - actions        `load`, `save`, `refreshMappableFields`,
 *                    `refreshEndpointTokenSuggestions`
 *
 * Mutations happen in the actions only; tab components read `state` and
 * write to `state.link` directly via v-model. The `dirty` flag flips on
 * the first write after load and resets after a successful save — drives
 * the save button's enabled state.
 */

const initial = () => ({
    link: null,            // serialized link, mutable via tab components
    options: null,         // small bundle of always-needed options
    meta: null,            // isNew, readOnly, handle, csrf info
    mappable: null,        // {fields, groups, matchOptions}; lazy-loaded
    mappableError: null,
    tokenSuggestions: null,// list of {kind, label, data[]}; lazy-loaded
    sample: null,          // last successful Fetch-sample report
    sampling: false,
    sampleError: null,
    loading: false,
    loadError: null,       // fatal error from the bootstrap fetch
    saving: false,
    dirty: false,
    errors: {},            // attribute → message[]
});

const root = reactive(initial());

let suppressDirty = false;
const markDirty = () => {
    if (!suppressDirty) root.dirty = true;
};

/**
 * Hydrate the SPA. Run once when the root component mounts. Resets dirty
 * (via the suppress flag) since the first reactive write that follows is
 * just the initial assignment, not a user edit.
 */
async function load(handle) {
    root.loading = true;
    root.loadError = null;
    root.errors = {};
    try {
        const data = await api.bootstrap(handle);
        if (data?.ok === false) {
            // Controller caught an exception and returned a JSON envelope.
            root.loadError = data.error || 'Failed to load link.';
            return;
        }
        api.configureCsrf({ name: data.meta.csrfTokenName, value: data.meta.csrfToken });

        suppressDirty = true;
        root.link    = data.link;
        root.options = data.options;
        root.meta    = data.meta;
        // Reset lazily-loaded caches whenever we re-bootstrap so the next
        // tab activation re-fetches against the new link.
        root.mappable = null;
        root.tokenSuggestions = null;
        root.dirty = false;
        suppressDirty = false;
    } catch (e) {
        const body = e.body || {};
        root.loadError = body.error || e.message || 'Failed to load link.';
        console.error('[influx] bootstrap failed', e);
    } finally {
        root.loading = false;
    }
}

/**
 * Persist the current link state. On validation failure the server returns
 * `{ok: false, errors}` — we store the errors but don't clear `dirty` since
 * the user still has unsaved changes.
 */
/**
 * Persist the current link state. Centralizes the post-save UX so every
 * trigger (Cmd+S, the save button, Save-and-continue) shares the same
 * redirect / toast behavior:
 *   - new link → redirect to its real edit URL (must reload to drop the `new` route)
 *   - plain save → return to /influx/links unless `continueEditing` is set
 *   - validation errors → store on state.errors and surface a top toast
 */
async function save(options = {}) {
    const { continueEditing = false } = options;
    if (root.saving || !root.link) return { ok: false };
    root.saving = true;
    root.errors = {};
    try {
        const result = await api.save(root.link);
        if (!result.ok) {
            root.errors = result.errors || {};
            if (window.Craft?.cp?.displayError) {
                Craft.cp.displayError(Craft.t('influx', "Couldn't save link."));
            }
            return { ok: false, errors: root.errors };
        }

        const wasNew = !!(root.meta && root.meta.isNew);
        const savedHandle = result.link?.handle;

        // Replace the local link with the server's canonical version
        // before any redirect — covers the case where the server applied
        // a normalisation (e.g. trimmed a handle).
        suppressDirty = true;
        root.link = result.link;
        root.dirty = false;
        suppressDirty = false;

        if (window.Craft?.cp?.displayNotice) {
            Craft.cp.displayNotice(Craft.t('influx', 'Link saved.'));
        }

        if (wasNew && savedHandle) {
            // The `new` URL is gone — full reload lands the SPA on the
            // persistent edit URL so subsequent saves update in place.
            window.location.href = Craft.getCpUrl(
                `influx/links/${encodeURIComponent(savedHandle)}/edit`,
            );
            return { ok: true, redirected: true };
        }

        if (!continueEditing) {
            window.location.href = Craft.getCpUrl('influx/links');
            return { ok: true, redirected: true };
        }

        return { ok: true };
    } finally {
        root.saving = false;
    }
}

/**
 * Refetch the mappable-fields / match-attribute options for the current
 * element type / criteria. Called by tab components when the user changes
 * the section or entry-type dropdown.
 */
async function refreshMappableFields() {
    if (!root.link) return;
    const elementType = root.link.elementType || '';
    if (elementType === '') {
        // Brand-new link with no element type yet. Don't hit the server
        // with a request that's guaranteed to 400; just clear the cache
        // so the tab shows the "pick an element type" empty-state.
        root.mappable = { fields: [], groups: [], matchOptions: [] };
        root.mappableError = null;
        return;
    }
    root.mappableError = null;
    try {
        root.mappable = await api.mappableFields(elementType, root.link.elementCriteria || {});
    } catch (e) {
        const msg = e.body?.message || e.message || 'Failed to load mappable fields.';
        root.mappableError = msg;
        // Make the failure visible in dev tools even when the tab UI is
        // hidden — easier to diagnose 4xx responses from the server.
        console.error('[influx] mappable-fields fetch failed', e);
    }
}

/**
 * Hit the configured endpoint and stash the inspection report — root /
 * paginator candidates, flat node list, mapping suggestions. The
 * Pagination and (later) Mapping tabs read from the same report so a
 * single fetch primes both.
 */
async function fetchSample() {
    if (!root.link || root.sampling) return;
    root.sampling = true;
    root.sampleError = null;
    try {
        const result = await api.sample({
            endpoint:      root.link.endpoint,
            rootNode:      root.link.rootNode,
            paginatorNode: root.link.paginatorNode,
            auth:          root.link.auth,
        });
        if (result.ok) {
            root.sample = result.report;
        } else {
            root.sampleError = result.message || 'Sample fetch failed.';
        }
    } catch (e) {
        root.sampleError = e.body?.message || e.message || 'Sample fetch failed.';
    } finally {
        root.sampling = false;
    }
}

async function refreshEndpointTokenSuggestions() {
    if (!root.link) return;
    const { suggestions } = await api.endpointTokenSuggestions(
        root.link.elementType,
        root.link.elementCriteria || {},
    );
    root.tokenSuggestions = suggestions;
}

/**
 * Mark the store dirty. Tab components don't need to call this directly —
 * v-model writes hit `state.link.*` which is reactive, and we install a
 * proxy-style watcher inside the root component to flip the flag.
 */
function touch() {
    markDirty();
}

export const store = {
    state: readonly(root),
    raw: root, // for v-model bindings; tab components write here
    load,
    save,
    fetchSample,
    refreshMappableFields,
    refreshEndpointTokenSuggestions,
    touch,
};
