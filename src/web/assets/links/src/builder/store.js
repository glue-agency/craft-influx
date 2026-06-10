import { computed, reactive, readonly } from 'vue';
import * as api from './api.js';

/**
 * Minimal reactive store for the LinkBuilder SPA. Vue's `reactive()` + a
 * handful of named actions is enough surface area — no Pinia / Vuex layer
 * needed for a single-screen builder.
 *
 * The store exposes:
 *   - `link`         the editable document — the ONE mutable surface.
 *                    Always access through the getter (a computed in
 *                    components): load()/save() replace the underlying
 *                    object, so capturing it once goes stale.
 *   - `ui`           read-only proxy of everything else (loading flags,
 *                    options, sample, errors, …)
 *   - `isDirty`      computed: the link differs from the last loaded/saved
 *                    snapshot
 *   - actions        `load`, `save`, `fetchSample`, `refreshMappableFields`,
 *                    `refreshEndpointTokenSuggestions`
 *
 * Mutation doctrine: components may v-model onto `link.*`; everything
 * else (async work, redirects, toasts) goes through the actions.
 *
 * Error doctrine: every api.* helper throws ApiError on any failure, so
 * each action's catch block reads `e.message` / `e.errors` — no response-
 * shape branching anywhere.
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
    savedSnapshot: null,   // JSON of the link as last loaded/saved
    errors: {},            // attribute → message[]
});

const root = reactive(initial());

/**
 * Derived dirty state: compare the live link against the snapshot taken at
 * load/save time. No flags to suppress or reset — and reverting an edit by
 * hand reads as clean again, which an imperative flag can't do.
 */
const isDirty = computed(() => {
    if (!root.link || root.savedSnapshot === null) return false;
    return JSON.stringify(root.link) !== root.savedSnapshot;
});

function rememberSnapshot() {
    root.savedSnapshot = root.link ? JSON.stringify(root.link) : null;
}

/**
 * Hydrate the SPA. Run once when the root component mounts.
 */
async function load(handle) {
    root.loading = true;
    root.loadError = null;
    root.errors = {};
    try {
        const data = await api.bootstrap(handle);
        api.configureCsrf({ name: data.meta.csrfTokenName, value: data.meta.csrfToken });

        root.link    = data.link;
        root.options = data.options;
        root.meta    = data.meta;
        // Reset lazily-loaded caches whenever we re-bootstrap so the next
        // tab activation re-fetches against the new link.
        root.mappable = null;
        root.tokenSuggestions = null;
        rememberSnapshot();
    } catch (e) {
        root.loadError = e.message || 'Failed to load link.';
        console.error('[influx] bootstrap failed', e);
    } finally {
        root.loading = false;
    }
}

/**
 * Persist the current link state. Centralizes the post-save UX so every
 * trigger (Cmd+S, the save button, Save-and-continue) shares the same
 * redirect / toast behavior:
 *   - new link → redirect to its real edit URL (must reload to drop the `new` route)
 *   - plain save → return to /influx/links unless `continueEditing` is set
 *   - validation errors → ApiError carries them; stored on state.errors
 *     plus a top toast. The snapshot stays put, so the link reads dirty.
 */
async function save(options = {}) {
    const { continueEditing = false } = options;
    if (root.saving || !root.link) return { success: false };
    root.saving = true;
    root.errors = {};
    try {
        const result = await api.save(root.link);

        const wasNew = !!(root.meta && root.meta.isNew);
        const savedHandle = result.link?.handle;

        // Replace the local link with the server's canonical version
        // before any redirect — covers the case where the server applied
        // a normalisation (e.g. trimmed a handle).
        root.link = result.link;
        rememberSnapshot();

        if (window.Craft?.cp?.displayNotice) {
            Craft.cp.displayNotice(Craft.t('influx', 'Link saved.'));
        }

        if (wasNew && savedHandle) {
            // The `new` URL is gone — full reload lands the SPA on the
            // persistent edit URL so subsequent saves update in place.
            window.location.href = Craft.getCpUrl(
                `influx/links/${encodeURIComponent(savedHandle)}/edit`,
            );
            return { success: true, redirected: true };
        }

        if (!continueEditing) {
            window.location.href = Craft.getCpUrl('influx/links');
            return { success: true, redirected: true };
        }

        return { success: true };
    } catch (e) {
        root.errors = e.errors || {};
        if (window.Craft?.cp?.displayError) {
            Craft.cp.displayError(e.message || Craft.t('influx', "Couldn't save link."));
        }
        return { success: false, errors: root.errors };
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
        root.mappableError = e.message || 'Failed to load mappable fields.';
        // Make the failure visible in dev tools even when the tab UI is
        // hidden — easier to diagnose 4xx responses from the server.
        console.error('[influx] mappable-fields fetch failed', e);
    }
}

/**
 * Hit the configured endpoint and stash the inspection report — root /
 * paginator candidates, flat node list, mapping suggestions. The
 * Pagination and Mapping tabs read from the same report so a single
 * fetch primes both.
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
        root.sample = result.report;
    } catch (e) {
        root.sampleError = e.message || 'Sample fetch failed.';
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

export const store = {
    ui: readonly(root),
    /** The editable document — components v-model onto this (via a computed). */
    get link() {
        return root.link;
    },
    isDirty,
    load,
    save,
    fetchSample,
    refreshMappableFields,
    refreshEndpointTokenSuggestions,
};
