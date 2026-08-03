<template>
    <teleport v-if="mounted && slotEl" :to="slotEl">
        <div class="influx-header-actions">
            <!-- Save split-button. Native Craft `.btngroup.submit.first`
                 with the chevron driven by Garnish's MenuBtn — the same
                 jQuery plugin every other CP screen uses. We init it
                 manually in mounted() because Garnish only auto-binds at
                 document.ready and our buttons mount after the SPA's
                 async bootstrap. Hidden entirely in a read-only
                 environment — matching Craft's own read-only settings
                 screens, which show no save action at all. -->
            <div v-if="! readOnly" class="btngroup submit first" ref="saveRoot">
                <a
                    href="#"
                    class="btn submit"
                    :class="{ disabled: ! canSave && ! ui.saving }"
                    role="button"
                    @click.prevent="doSave({ continue: false })"
                    v-text="saveLabel"
                />

                <button
                    ref="menuBtn"
                    type="button"
                    class="btn submit menubtn"
                    :aria-label="$t('More save options')"
                />

                <div class="menu" ref="menu">
                    <ul>
                        <li>
                            <a
                                href="#"
                                @click.prevent="doSave({ continue: true })"
                                v-text="$t('Save and continue editing')"
                            />
                        </li>
                    </ul>

                    <template v-if="canDelete">
                        <hr>
                        <ul>
                            <li>
                                <!-- `error` + data-icon ride Craft's own destructive
                                     menu-item styling: red text, red trash glyph. -->
                                <a
                                    href="#"
                                    class="error"
                                    data-icon="trash"
                                    @click.prevent="doDelete"
                                    v-text="$t('Delete link')"
                                />
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
 * The LinkBuilder's header action, teleported into Craft's standard cpScreen
 * `#action-buttons` slot so it reads as a native CP header action: the Save
 * split-button — primary action plus a disclosure menu for "Save and continue
 * editing" (vs. plain Save, which navigates back to the link index) and the
 * destructive Delete.
 *
 * Fetch sample used to sit beside it and now lives in the details sidebar
 * ({@see DetailsSidebar.vue}), where its state has a line to be reported on.
 *
 * The teleport target is a `<div data-influx-actions-slot>` rendered into
 * cpScreen.additionalButtonsHtml() by LinksController::builderScreen(), the
 * helper both the edit and duplicate actions render through. We
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
        };
    },

    computed: {
        readOnly() {
            return !!this.ui.meta?.readOnly;
        },

        canSave() {
            return !!this.ui.link && ! this.readOnly && store.isDirty.value && ! this.ui.saving;
        },

        // Delete needs a persisted link (a uid) and a writable environment —
        // a brand-new unsaved link has nothing to delete yet.
        canDelete() {
            return !!this.ui.meta?.uid && ! this.ui.meta?.isNew && ! this.ui.meta?.readOnly;
        },

        saveLabel() {
            return this.ui.saving ? this.$t('Saving…') : this.$t('Save');
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
        doSave({ continue: keepEditing }) {
            if (! this.canSave) return;
            // Toast + redirect logic lives in store.save() so Cmd+S and
            // both buttons here share identical behavior.
            store.save({ continueEditing: keepEditing });
        },

        doDelete() {
            if (! this.canDelete) return;

            if (! window.confirm(this.$t('Are you sure you want to delete this link? Its sync configuration is removed permanently — imported elements stay.'))) {
                return;
            }

            // Toast + redirect live in the store action.
            store.deleteLink();
        },
    },
};
</script>
