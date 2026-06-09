<template>
    <teleport v-if="mounted && slotEl" :to="slotEl">
        <div class="influx-header-actions">
            <!-- Fetch sample — single button, label changes with state.
                 Idle: "Fetch sample" with the download data-icon.
                 Fetching: "Fetching…" (button disabled).
                 Fetched: "Refetch sample" (no decoration — Pagination tab
                          surfaces the populated dropdowns).
                 Error: "Refetch sample" + small red status dot, error
                        message in the title tooltip. -->
            <button
                type="button"
                class="btn influx-fetch-btn"
                :class="fetchBtnClass"
                :data-icon="state.sampling ? null : 'download'"
                :disabled="!canSample || state.sampling"
                :title="fetchTitle"
                @click="onFetch"
            >
                {{ fetchLabel }}
                <span
                    v-if="state.sampleError"
                    class="influx-fetch-status"
                    data-state="error"
                    aria-hidden="true"
                ></span>
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
                    :class="{ disabled: !canSave && !state.saving }"
                    role="button"
                    @click.prevent="doSave({ continue: false })"
                >{{ saveLabel }}</a>

                <button
                    ref="menuBtn"
                    type="button"
                    class="btn submit menubtn"
                    data-icon="settings"
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
            state: store.state,
        };
    },

    computed: {
        canSave() {
            return !!this.state.link && this.state.dirty && !this.state.saving;
        },

        saveLabel() {
            return this.state.saving ? this.$t('Saving…') : this.$t('Save');
        },

        canSample() {
            const ep = this.state.link?.endpoint;
            return typeof ep === 'string' && ep.trim() !== '';
        },

        fetchBtnClass() {
            if (this.state.sampling) return 'is-fetching';
            if (this.state.sampleError) return 'is-error';
            if (this.state.sample) return 'is-fetched';
            return 'is-idle';
        },

        fetchStatusDot() {
            if (this.state.sampling) return null;
            if (this.state.sampleError) return 'error';
            if (this.state.sample) return 'success';
            return null;
        },

        fetchLabel() {
            if (this.state.sampling) return this.$t('Fetching…');
            if (this.state.sample || this.state.sampleError) return this.$t('Refetch sample');
            return this.$t('Fetch sample');
        },

        fetchTitle() {
            if (!this.canSample) return this.$t('Set a Base Endpoint on the General tab first');
            if (this.state.sampling) return this.$t('Fetching sample…');
            if (this.state.sampleError) return this.$t('Last attempt failed: {message}', { message: this.state.sampleError });
            if (this.state.sample?.url) return this.$t('Last fetched from {url}', { url: this.state.sample.url });
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
    },
};
</script>
