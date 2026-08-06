<template>
    <teleport v-if="slotEl" :to="slotEl">
        <fieldset class="influx-details-section">
            <legend v-text="$t('Sample')"></legend>

            <div class="meta">
                <div class="field">
                    <div class="heading">
                        <label>
                            <span class="status" :class="sampleStatusColor"></span>
                            <span v-text="sampleSummary"></span>
                        </label>
                    </div>
                </div>

                <!-- Stays where admin changes aren't allowed: a fetch reads the
                     remote feed and touches no config, the endpoint it hits is
                     the saved one, and the screen already auto-fetches on load.
                     Without it the mappings can't be read against a fresh
                     sample — which is the whole point of the screen there. -->
                <div class="field">
                    <div class="input ltr">
                        <button
                            type="button"
                            class="btn influx-details-btn"
                            :data-icon="ui.sampling ? 'refresh' : 'download'"
                            :class="{ 'is-spinning': ui.sampling }"
                            :disabled="! canSample || ui.sampling"
                            :title="fetchTitle"
                            @click="onFetch"
                            v-text="fetchLabel"
                        />
                    </div>
                </div>
            </div>
        </fieldset>

        <!-- Mapping progress, and the one action that can move it without
             visiting the tab. Absent until the field tree has loaded — an
             element type has to be picked before either means anything. -->
        <fieldset v-if="fieldCount" class="influx-details-section">
            <legend v-text="$t('Mappings')"></legend>

            <div class="meta">
                <div class="field">
                    <div class="heading">
                        <label v-text="$t('{mapped} of {total} fields mapped', { mapped: mappedCount, total: fieldCount })"></label>
                    </div>
                </div>

                <div v-if="missingCount" class="field">
                    <div class="heading">
                        <label>
                            <span class="influx-details-warning" v-text="missingLabel"></span>
                        </label>
                    </div>
                </div>

                <div v-if="! readOnly" class="field">
                    <div class="input ltr">
                        <button
                            type="button"
                            class="btn influx-details-btn"
                            data-icon="wand"
                            :disabled="! canAutoMatch"
                            :title="autoMatchTitle"
                            @click="onAutoMatch"
                            v-text="$t('Auto-match')"
                        />
                    </div>
                </div>
            </div>
        </fieldset>
    </teleport>
</template>

<script>
import { store } from './store.js';
import { discoveredNodes, isMapped } from './lib/mappings.js';

/**
 * The builder's details rail: what state the link is in, and the two actions
 * that change it from outside a tab.
 *
 * Teleported into the `<div data-influx-details-slot>` that
 * LinksController::builderScreen() renders through cpScreen.metaSidebarTemplate()
 * — the same arrangement as HeaderActions and Craft's `#action-buttons`. The
 * rail itself, its collapse toggle and that toggle's persisted state are
 * Craft's; this only fills it.
 *
 * The link's own facts (id, last run, timestamps) are rendered by the host
 * template instead: they only change on save, which reloads the screen.
 *
 * Fetch sample lives here rather than in the header because the sample is
 * ambient state, not a header action — the status line it needs has nowhere to
 * go up there, and the two Fetch-dependent tabs are the ones reading it.
 */
export default {
    name: 'DetailsSidebar',

    data() {
        return {
            ui: store.ui,
            slotEl: null,
        };
    },

    computed: {
        link() { return store.link; },

        readOnly() { return !!this.ui.meta?.readOnly; },

        // Through the store, which resolves it the way the fetch itself does
        // (site-specific mode samples against the first filled site endpoint,
        // since the base endpoint is hidden there).
        canSample() { return store.canSample.value; },

        // Craft's status-dot vocabulary: green for a usable sample, orange
        // while fetching or when the report came back partial, red for a
        // failed attempt, hollow for "never fetched".
        sampleStatusColor() {
            if (this.ui.sampling) return 'pending';
            if (this.ui.sampleError) return 'red';
            if (this.ui.sampleWarning) return 'orange';
            if (this.ui.sample) return 'live';

            return '';
        },

        /** The one line that says where the sample stands. */
        sampleSummary() {
            if (this.ui.sampling) return this.$t('Fetching…');
            if (this.ui.sampleError) return this.$t('Fetch failed');
            if (this.ui.sampleWarning) return this.$t('Incomplete — no items resolved');
            if (! this.ui.sample) return this.$t('Not fetched yet');

            return this.$t('Fetched — {items} items, {nodes} nodes', {
                items: this.ui.sample.itemCount ?? 0,
                nodes: (this.ui.sample.flatNodes || []).length,
            });
        },

        fetchLabel() {
            if (this.ui.sampling) return this.$t('Fetching sample');

            return this.ui.sample || this.ui.sampleError
                ? this.$t('Refetch sample')
                : this.$t('Fetch sample');
        },

        fetchTitle() {
            if (! this.canSample) return this.$t('Set a Base Endpoint on the General tab first');
            if (this.ui.sampleError) return this.$t('Last attempt failed: {message}', { message: this.ui.sampleError });
            if (this.ui.sampleWarning) return this.$t('Sample incomplete: {message}', { message: this.ui.sampleWarning });
            if (this.ui.sample?.url) return this.$t('Last fetched from {url}', { url: this.ui.sample.url });

            return this.$t('Hit the configured endpoint and inspect the response');
        },

        /** Every mappable field the tree offers, across all its groups. */
        fieldCount() {
            return (this.ui.mappable?.fields || []).length;
        },

        // The same rule a group header's pill counts by — one implementation, so
        // the total and the per-group pills can't disagree.
        mappedCount() {
            const mappings = this.link?.mappings || {};

            return (this.ui.mappable?.fields || [])
                .filter((field) => isMapped(field, mappings[field.handle]))
                .length;
        },

        /**
         * Saved source nodes the latest sample doesn't carry. Null discovered
         * nodes mean "can't know" (no sample, or a partial one), which flags
         * nothing — the same contract the rows follow.
         */
        missingCount() {
            const discovered = discoveredNodes(this.ui.sample);
            if (! discovered) return 0;

            const available = new Set(discovered.map((option) => option.value));
            const mappings = this.link?.mappings || {};

            return (this.ui.mappable?.fields || []).reduce((count, field) => {
                const saved = mappings[field.handle]?.node;
                if (! saved) return count;

                return count + (available.has(saved) ? 0 : 1);
            }, 0);
        },

        missingLabel() {
            return this.missingCount === 1
                ? this.$t('1 field is missing its source node')
                : this.$t('{count} fields are missing their source node', { count: this.missingCount });
        },

        // Suggestions come off the sample, so there is nothing to match before
        // one has been fetched.
        canAutoMatch() {
            return ! this.readOnly && (this.ui.sample?.mappingSuggestions || []).length > 0;
        },

        autoMatchTitle() {
            return this.canAutoMatch
                ? this.$t('Map every field whose handle matches a node in the sample. Existing mappings are left alone.')
                : this.$t('Fetch a sample first — its nodes are what gets matched.');
        },
    },

    mounted() {
        this.slotEl = document.querySelector('[data-influx-details-slot]');
    },

    methods: {
        onFetch() {
            store.fetchSample();
        },

        onAutoMatch() {
            if (! this.canAutoMatch) return;

            store.autoMatch();
        },
    },
};
</script>
