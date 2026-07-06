import { computed, reactive, readonly, watch } from 'vue';
import * as api from './api.js';
import { errorText, notifyError, notifyNotice, t } from './lib/notify.js';

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
    // UI mode: the link is endpoint-per-site instead of one base endpoint.
    // Derived from the saved data on load, then owned by the General tab's
    // lightswitch. Not persisted itself — a link "is" site-specific when
    // it has siteEndpoints; save() enforces that invariant.
    siteEndpointsMode: false,
});

const root = reactive(initial());

/**
 * Derived dirty state: compare the live link against the snapshot taken at
 * load/save time. No flags to suppress or reset — and reverting an edit by
 * hand reads as clean again, which an imperative flag can't do.
 *
 * Cost assessed (2026-07): it's a cached computed, so the stringify runs at
 * most once per link mutation — sub-ms even at ~50 mappings. Revisit only if
 * links grow orders of magnitude larger.
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
async function load(id) {
    root.loading = true;
    root.loadError = null;
    root.errors = {};
    try {
        const data = await api.bootstrap(id);
        api.configureCsrf({ name: data.meta.csrfTokenName, value: data.meta.csrfToken });

        root.link    = data.link;
        root.options = data.options;
        root.meta    = data.meta;
        root.siteEndpointsMode = (data.link.siteEndpoints || []).length > 0;
        // Reset lazily-loaded caches whenever we re-bootstrap so the next
        // tab activation re-fetches against the new link.
        root.mappable = null;
        root.tokenSuggestions = null;
        rememberSnapshot();
        // Existing links with a configured endpoint prime the sample
        // immediately — no manual Fetch click per editing session.
        evaluateSample();
    } catch (e) {
        root.loadError = errorText(e, 'Failed to load link.');
        console.error('[influx] bootstrap failed', e);
    } finally {
        root.loading = false;
    }
}

/**
 * Persist the current link state. Centralizes the post-save UX so every
 * trigger (Cmd+S, the save button, Save-and-continue) shares the same
 * redirect / toast behavior:
 *   - new link → redirect to its real edit URL by id (must reload to drop the `new` route)
 *   - plain save → return to /influx/links unless `continueEditing` is set
 *   - validation errors → ApiError carries them; stored on state.errors
 *     plus a top toast. The snapshot stays put, so the link reads dirty.
 */
async function save(options = {}) {
    const { continueEditing = false } = options;
    if (root.saving || !root.link) return { success: false };
    // Read-only environment: the save UI is hidden, but Cmd+S still routes
    // here — drop it client-side (the controller 403s as the backstop).
    if (root.meta?.readOnly) return { success: false };
    root.errors = {};

    // Site-specific mode without a single usable site endpoint can't be
    // caught server-side (the lightswitch itself isn't persisted, and a
    // hidden base endpoint would satisfy the model rule), so it's the one
    // validation that lives client-side.
    if (root.siteEndpointsMode && !hasAnySiteEndpoint()) {
        const message = t('Add at least one site endpoint, or turn site-specific endpoints off.');
        root.errors = { siteEndpoints: [message] };
        notifyError(message);
        return { success: false, errors: root.errors };
    }

    root.saving = true;
    try {
        const result = await api.save(root.link);

        const wasNew = !!(root.meta && root.meta.isNew);
        const savedId = result.link?.id;

        // Replace the local link with the server's canonical version
        // before any redirect — covers the case where the server applied
        // a normalisation (e.g. trimmed a handle).
        root.link = result.link;
        rememberSnapshot();

        notifyNotice(t('Link saved.'));

        if (wasNew && savedId) {
            // The `new` URL is gone — full reload lands the SPA on the
            // persistent edit URL so subsequent saves update in place.
            window.location.href = Craft.getCpUrl(
                `influx/links/${encodeURIComponent(savedId)}/edit`,
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
        notifyError(errorText(e, t("Couldn't save link.")));
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
        root.mappableError = errorText(e, 'Failed to load mappable fields.');
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
    const key = sampleKey();
    root.sampling = true;
    root.sampleError = null;
    try {
        const result = await api.sample({
            endpoint:      sampleEndpoint() || null,
            rootNode:      root.link.rootNode,
            paginatorNode: root.link.paginatorNode,
            auth:          root.link.auth,
        });
        root.sample = result.report;
    } catch (e) {
        root.sampleError = errorText(e, 'Sample fetch failed.');
        // No inline error block anywhere — failures surface as a native CP
        // toast, plus the header button's error state via `sampleError`.
        notifyError(t('Sample failed: {message}', { message: root.sampleError }));
    } finally {
        // Recorded even on failure so the auto-fetcher doesn't loop on a
        // broken endpoint — the manual Fetch button always retries.
        fetchedSampleKey = key;
        root.sampling = false;
    }
}

/**
 * The endpoint a sample fetch should hit. In site-specific mode the base
 * endpoint is hidden (and possibly stale), so sampling goes against the
 * first filled-in site endpoint instead. Empty string when there's
 * nothing usable to fetch.
 */
function sampleEndpoint() {
    const link = root.link;
    if (!link) return '';

    if (root.siteEndpointsMode) {
        for (const row of link.siteEndpoints || []) {
            const trimmed = typeof row?.endpoint === 'string' ? row.endpoint.trim() : '';
            if (trimmed) return trimmed;
        }
        return '';
    }

    return typeof link.endpoint === 'string' ? link.endpoint.trim() : '';
}

/** Whether any site-endpoint row is actually filled in. */
function hasAnySiteEndpoint() {
    return (root.link?.siteEndpoints || [])
        .some((row) => typeof row?.endpoint === 'string' && row.endpoint.trim() !== '');
}

/**
 * The inputs the sample report materially depends on. Null when there's
 * nothing to fetch yet.
 */
function sampleKey() {
    const endpoint = sampleEndpoint();
    if (!endpoint) return null;
    return `${endpoint} ${root.link.rootNode ?? ''}`;
}

let fetchedSampleKey = null;
let sampleAutoTimer = null;

/**
 * Keyed auto-fetch: run the sample when the (endpoint, rootNode) pair
 * differs from what's already been fetched. Triggered on load, when the
 * endpoint field loses focus (typing alone never fetches — half-typed
 * URLs shouldn't hit the network), and by the root-node watcher below.
 * Re-checks shortly after an in-flight fetch instead of dropping newer
 * config; save()'s canonical-link swap is a no-op thanks to the key.
 */
function evaluateSample() {
    clearTimeout(sampleAutoTimer);
    const key = sampleKey();
    if (!key || key === fetchedSampleKey) return;
    if (root.sampling) {
        sampleAutoTimer = setTimeout(evaluateSample, 400);
        return;
    }
    return fetchSample();
}

// The report's node lists depend on the root node — re-evaluate when it
// changes. A discrete select change, unlike endpoint typing, so no blur
// handshake needed.
watch(() => root.link?.rootNode, () => evaluateSample());

/**
 * Flip the General tab's "Site-specific endpoints" mode. Pure UI state —
 * turning it off keeps any configured siteEndpoints (the editor is just
 * hidden), matching the other lightswitches' don't-lose-config behavior.
 * Re-evaluates the sample because the effective sample endpoint changes
 * with the mode.
 */
function setSiteEndpointsMode(on) {
    root.siteEndpointsMode = !!on;
    evaluateSample();
}

/**
 * Delete the saved link and land back on the links overview. Confirmation
 * is the caller's job (the header menu) — this action just executes.
 * Unsaved edits are irrelevant once the link itself is gone, so the
 * snapshot is refreshed first and the dirty guard can't block the exit.
 */
async function deleteLink() {
    const uid = root.meta?.uid;
    if (!uid || root.saving) return { success: false };

    try {
        await api.deleteLink(uid);
        rememberSnapshot();
        notifyNotice(t('Link deleted.'));
        window.location.href = Craft.getCpUrl('influx/links');
        return { success: true, redirected: true };
    } catch (e) {
        notifyError(errorText(e, t("Couldn't delete link.")));
        return { success: false };
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
    deleteLink,
    fetchSample,
    evaluateSample,
    setSiteEndpointsMode,
    refreshMappableFields,
    refreshEndpointTokenSuggestions,
};
