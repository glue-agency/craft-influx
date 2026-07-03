<template>
    <teleport v-if="mounted && slotEl" :to="slotEl">
        <div class="influx-header-actions">
            <!-- Fetch sample — single button whose icon carries the state.
                 Idle: download (cloud) icon, "Fetch sample".
                 Fetching: spinning refresh icon, "Fetching sample"
                           (button disabled).
                 Fetched: green check icon, "Sample fetched"; hovering
                          swaps the label to "Refetch sample".
                 Error: red cross icon, "Fetch failed"; hovering swaps the
                        label to "Refetch sample", error message in the
                        title tooltip. -->
            <button
                type="button"
                class="btn influx-fetch-btn"
                :class="fetchBtnClass"
                :data-icon="fetchIcon"
                :disabled="!canSample || ui.sampling"
                :title="fetchTitle"
                @click="onFetch"
                @mouseenter="fetchHovered = true"
                @mouseleave="fetchHovered = false"
            >
                {{ fetchLabel }}
            </button>

            <!-- Save split-button. Native Craft `.btngroup.submit.first`
                 with the chevron driven by Garnish's MenuBtn — the same
                 jQuery plugin every other CP screen uses. We init it
                 manually in mounted() because Garnish only auto-binds at
                 document.ready and our buttons mount after the SPA's
                 async bootstrap. -->
            <div class="btngroup submit first" ref="saveRoot">
                <a
                    href="#"
                    class="btn submit"
                    :class="{ disabled: !canSave && !ui.saving }"
                    role="button"
                    @click.prevent="doSave({ continue: false })"
                >{{ saveLabel }}</a>

                <button
                    ref="menuBtn"
                    type="button"
                    class="btn submit menubtn"
                    :aria-label="$t('More save options')"
                ></button>

                <div class="menu" ref="menu">
                    <ul>
                        <li>
                            <a
                                href="#"
                                @click.prevent="doSave({ continue: true })"
                            >{{ $t('Save and continue editing') }}</a>
                        </li>
                    </ul>

                    <template v-if="canDelete">
                        <hr>
                        <ul>
                            <li>
                                <a
                                    href="#"
                                    class="error"
                                    @click.prevent="doDelete"
                                >{{ $t('Delete link') }}</a>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
        </div>
    </teleport>
</template>

<script>
import { store } from './store.js';

/**
 * Top-right action buttons for the LinkBuilder, teleported into Craft's
 * standard cpScreen `#action-buttons` slot so they read as native CP
 * header actions.
 *
 * Two buttons:
 *   1. **Fetch sample** — formerly inside the Pagination tab. Surfaces
 *      ambient state at all times via a status indicator so the user
 *      knows whether they have a sample loaded before switching to the
 *      Pagination / Mapping tabs that depend on it.
 *   2. **Save split-button** — primary action plus a disclosure menu for
 *      "Save and continue editing" (vs. plain Save, which navigates back
 *      to the link index).
 *
 * The teleport target is a `<div data-influx-actions-slot>` rendered into
 * cpScreen.additionalButtonsHtml() by LinksController::actionEdit. We
 * wait for the document mounted hook before teleporting so the slot is
 * guaranteed present in the DOM.
 */
export default {
    name: 'HeaderActions',

    data() {
        return {
            mounted: false,
            slotEl: null,
            ui: store.ui,
            fetchHovered: false,
        };
    },

    computed: {
        canSave() {
            return !!this.ui.link && store.isDirty.value && !this.ui.saving;
        },

        // Delete needs a persisted link (a uid) and a writable environment —
        // a brand-new unsaved link has nothing to delete yet.
        canDelete() {
            return !!this.ui.meta?.uid && !this.ui.meta?.isNew && !this.ui.meta?.readOnly;
        },

        saveLabel() {
            return this.ui.saving ? this.$t('Saving…') : this.$t('Save');
        },

        canSample() {
            const link = this.ui.link;
            if (!link) return false;
            // Site-specific mode samples against the first filled site
            // endpoint (the base endpoint is hidden there) — mirrors the
            // store's sampleEndpoint() resolution.
            if (this.ui.siteEndpointsMode) {
                return Object.values(link.siteEndpoints || {})
                    .some((url) => typeof url === 'string' && url.trim() !== '');
            }
            const ep = link.endpoint;
            return typeof ep === 'string' && ep.trim() !== '';
        },

        fetchBtnClass() {
            if (this.ui.sampling) return 'is-fetching';
            if (this.ui.sampleError) return 'is-error';
            if (this.ui.sample) return 'is-fetched';
            return 'is-idle';
        },

        // Ligature names that exist in both the Craft 4 and Craft 5 icon
        // fonts ("refresh"/"remove" are legacy aliases in Craft 5). The
        // fonts have no cloud-check/cloud-cross glyph, so the fetched and
        // error states fall back to a plain check/cross, colored via the
        // is-fetched / is-error classes.
        fetchIcon() {
            if (this.ui.sampling) return 'refresh';
            if (this.ui.sampleError) return 'remove';
            if (this.ui.sample) return 'check';
            return 'download';
        },

        fetchLabel() {
            if (this.ui.sampling) return this.$t('Fetching sample');
            if (this.ui.sampleError) {
                return this.fetchHovered ? this.$t('Refetch sample') : this.$t('Fetch failed');
            }
            if (this.ui.sample) {
                return this.fetchHovered ? this.$t('Refetch sample') : this.$t('Sample fetched');
            }
            return this.$t('Fetch sample');
        },

        fetchTitle() {
            if (!this.canSample) return this.$t('Set a Base Endpoint on the General tab first');
            if (this.ui.sampling) return this.$t('Fetching sample…');
            if (this.ui.sampleError) return this.$t('Last attempt failed: {message}', { message: this.ui.sampleError });
            if (this.ui.sample?.url) return this.$t('Last fetched from {url}', { url: this.ui.sample.url });
            return this.$t('Hit the configured endpoint and inspect the response');
        },
    },

    mounted() {
        // Find Craft's #action-buttons slot — cpScreen renders it when at
        // least one of {additionalButtonsHtml, actionButton, actionMenu,
        // details} is set, which our controller guarantees.
        this.slotEl = document.querySelector('[data-influx-actions-slot]');
        this.mounted = !!this.slotEl;

        // Garnish's `$.fn.menubtn()` wires the chevron to its sibling
        // `.menu` div for show/hide + click-outside + keyboard nav. The
        // plugin only auto-binds at document.ready, so we trigger it
        // ourselves once Vue has teleported the markup into the header.
        this.$nextTick(() => {
            const $ = window.jQuery;
            if ($ && $.fn.menubtn && this.$refs.menuBtn) {
                $(this.$refs.menuBtn).menubtn();
            }
        });
    },

    methods: {
        onFetch() {
            store.fetchSample();
        },

        doSave({ continue: keepEditing }) {
            if (!this.canSave) return;
            // Toast + redirect logic lives in store.save() so Cmd+S and
            // both buttons here share identical behavior.
            store.save({ continueEditing: keepEditing });
        },

        doDelete() {
            if (!this.canDelete) return;

            if (!window.confirm(this.$t('Are you sure you want to delete this link? Its sync configuration is removed permanently — imported elements stay.'))) {
                return;
            }

            // Toast + redirect live in the store action.
            store.deleteLink();
        },
    },
};
</script>

<style>
/* Moved from links.css. */
/* ---------------------------------------------------------------------
   Header actions — teleported into Craft's cpScreen #action-buttons slot.
   Two primary controls: a state-aware Fetch sample trigger and a Save
   split-button (primary + disclosure menu for "Save and continue").
--------------------------------------------------------------------- */
.influx-header-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

/* Fetch sample button — standard Craft button whose data-icon carries the
   state: download (idle), spinning refresh (fetching), green check
   (fetched), red cross (error). The label updates alongside; see
   HeaderActions.vue. */
.influx-fetch-btn.is-fetching {
    color: var(--medium-text-color, #6b7280);
    cursor: progress;
}
.influx-fetch-btn.is-fetching::before {
    animation: influx-fetch-spin 1s linear infinite;
}
.influx-fetch-btn.is-fetched::before { color: #008549; }
.influx-fetch-btn.is-error::before { color: #cf1124; }

@keyframes influx-fetch-spin {
    to { transform: rotate(360deg); }
}

.influx-sample-error {
    background: #fdecec;
    border: 1px solid #f0c4c4;
    color: #8a1a1a;
    padding: 8px 12px;
    border-radius: 4px;
    margin: 0 0 16px;
}
</style>
