<template>
    <div class="influx-link-builder" :class="{ 'is-loading': state.loading }">
        <p v-if="state.loading" class="light">{{ $t('Loading…') }}</p>

        <div v-else-if="state.loadError" class="influx-link-builder-errors">
            <p><strong>{{ $t('Couldn’t load this link:') }}</strong> {{ state.loadError }}</p>
            <p class="light">{{ $t('Check the Craft logs for the full stack trace, or reload to retry.') }}</p>
        </div>

        <template v-else-if="state.link">
            <header-actions />

            <!-- Content panes. The tab nav is rendered by Craft's cpScreen
                 into the page header (see LinksController::actionEdit), and
                 Craft.Tabs.js toggles the `.hidden` class on these
                 elements when the user switches tabs. We seed the initial
                 `.hidden` state on all but #general so the first paint
                 matches the active tab without a flash of all-five-panes. -->
            <section id="general" class="influx-link-builder-tab">
                <general-tab />
            </section>
            <section id="pagination" class="influx-link-builder-tab hidden">
                <pagination-tab />
            </section>
            <section id="mapping" class="influx-link-builder-tab hidden">
                <mapping-tab />
            </section>
            <section id="authentication" class="influx-link-builder-tab hidden">
                <auth-tab />
            </section>
            <section id="settings" class="influx-link-builder-tab hidden">
                <settings-tab />
            </section>
        </template>
    </div>
</template>

<script>
import { store } from './store.js';
import GeneralTab from './tabs/GeneralTab.vue';
import PaginationTab from './tabs/PaginationTab.vue';
import MappingTab from './tabs/MappingTab.vue';
import AuthTab from './tabs/AuthTab.vue';
import SettingsTab from './tabs/SettingsTab.vue';
import HeaderActions from './HeaderActions.vue';

export default {
    name: 'LinkBuilder',

    components: { GeneralTab, PaginationTab, MappingTab, AuthTab, SettingsTab, HeaderActions },

    props: {
        // Pulled from the host template's data-bootstrap attribute.
        handle: { type: String, default: null },
    },

    data() {
        return {
            state: store.state,
        };
    },

    computed: {
        // Derived in the store by comparing against the snapshot taken at
        // load/save time — no imperative flag, so reverting an edit reads
        // as clean again.
        dirty() {
            return store.isDirty.value;
        },
    },

    watch: {
        // Mark cpScreen tabs with `.error` (Craft's CP CSS turns them red
        // and adds the warning icon) whenever the related attribute has
        // server-side validation errors. The tab nav lives outside our
        // Vue tree (rendered by Craft into #content-header), so we reach
        // out via DOM queries.
        'state.errors': {
            deep: true,
            handler(errors) {
                const tabMap = {
                    general:        ['name', 'handle', 'elementType', 'endpoint', 'processing'],
                    mapping:        ['match'],
                    authentication: ['auth'],
                };
                for (const [tabId, attrs] of Object.entries(tabMap)) {
                    const anchor = document.querySelector(`a[href="#${tabId}"][role="tab"]`);
                    if (!anchor) continue;
                    const hasError = attrs.some(a => (errors?.[a] || []).length > 0);
                    anchor.classList.toggle('error', hasError);
                }
            },
        },
    },

    created() {
        store.load(this.handle);

        // Replay URL hash → activate matching tab once the SPA has
        // mounted its content panes. Tabs.js init at document.ready ran
        // against an empty content area, so a deep-link like
        // `…/edit#mapping` would otherwise stay on General. The function-
        // source watch is more reliable than a string path here.
        let applied = false;
        const stop = this.$watch(
            () => this.state.link,
            (next) => {
                if (!next || applied) return;
                applied = true;
                this.$nextTick(() => {
                    this.applyInitialHash();
                    stop();
                });
            },
            { immediate: true },
        );
    },

    mounted() {
        // ⌘S / Ctrl-S → save and continue editing. Craft's CP screen
        // would otherwise submit the (empty) form — preventDefault wins.
        this._saveShortcutHandler = (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (this.state.saving || !this.dirty) return;
                store.save({ continueEditing: true });
            }
        };
        document.addEventListener('keydown', this._saveShortcutHandler);

        // beforeunload guard — every other CP screen does this.
        this._beforeUnloadHandler = (e) => {
            if (!this.dirty) return;
            e.preventDefault();
            e.returnValue = '';
            return '';
        };
        window.addEventListener('beforeunload', this._beforeUnloadHandler);
    },

    beforeUnmount() {
        if (this._saveShortcutHandler) {
            document.removeEventListener('keydown', this._saveShortcutHandler);
        }
        if (this._beforeUnloadHandler) {
            window.removeEventListener('beforeunload', this._beforeUnloadHandler);
        }
    },

    methods: {
        applyInitialHash() {
            // Craft's CP runs `initTabs` + `tabManager.selectTab(...)` at
            // document.ready, including selecting the tab matching
            // `window.LOCATION_HASH`. That fires its `.hidden`-toggle
            // handlers against `$(href)` — which, at that point, doesn't
            // exist yet because our SPA hasn't mounted the panes. So the
            // tab nav ends up with `.sel` on (e.g.) `#mapping`, but the
            // pane stays in our baked-in initial state (general visible).
            //
            // Fix-up: once the panes are mounted, sync `.hidden` on each
            // to match whichever anchor Craft already marked `.sel`. We
            // don't replay clicks because Craft.Tabs.selectTab early-
            // returns when the target equals `$selectedTab`, which would
            // skip the handlers entirely.
            const tabsContainer = document.getElementById('tabs');
            if (!tabsContainer) return;
            const selectedAnchor = tabsContainer.querySelector('a.sel[role="tab"]');
            if (!selectedAnchor) return;
            const targetHref = selectedAnchor.getAttribute('href');
            if (!targetHref) return;

            tabsContainer.querySelectorAll('a[role="tab"]').forEach(anchor => {
                const href = anchor.getAttribute('href');
                if (!href || href[0] !== '#') return;
                const pane = document.querySelector(href);
                if (pane) pane.classList.toggle('hidden', href !== targetHref);
            });
        },
    },
};
</script>
