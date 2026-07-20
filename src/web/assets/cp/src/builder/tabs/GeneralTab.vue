<template>
    <div class="influx-tab-general">
        <div class="field" :class="{ 'has-errors': errors.name?.length }">
            <div class="heading"><label for="builder-name">{{ $t('Name') }} <span class="influx-required" aria-hidden="true">*</span></label></div>
            <div class="instructions"><p>{{ $t('What this link will be called in the control panel.') }}</p></div>
            <div class="input ltr"><input id="builder-name" type="text" class="text fullwidth" v-model="link.name" :disabled="readOnly" /></div>
            <field-errors :messages="errors.name" />
        </div>

        <div class="field" :class="{ 'has-errors': errors.handle?.length }">
            <div class="heading"><label for="builder-handle">{{ $t('Handle') }} <span class="influx-required" aria-hidden="true">*</span></label></div>
            <div class="instructions"><p>{{ $t('Identifier used in console commands and event keys.') }}</p></div>
            <div class="input ltr"><input id="builder-handle" type="text" class="text fullwidth code" v-model="link.handle" :disabled="readOnly" /></div>
            <field-errors :messages="errors.handle" />
        </div>

        <hr>
        <h2>{{ $t('Element') }}</h2>

        <div class="field" :class="{ 'has-errors': errors.elementType?.length }">
            <div class="heading"><label for="builder-elementType">{{ $t('Element type') }} <span class="influx-required" aria-hidden="true">*</span></label></div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-elementType" v-model="link.elementType" :disabled="readOnly" @change="onElementTypeChange">
                        <option v-for="o in options.elementTypes" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>
            <field-errors :messages="errors.elementType" />
        </div>

        <div class="field" v-if="usesCriteria('section')">
            <div class="heading"><label for="builder-section">{{ $t('Section') }}</label></div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-section" v-model="section" :disabled="readOnly">
                        <option v-for="o in options.sections" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="field" v-if="usesCriteria('type')">
            <div class="heading"><label for="builder-entryType">{{ $t('Entry type') }}</label></div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-entryType" v-model="entryType" :disabled="readOnly">
                        <option value="">{{ $t('— select —') }}</option>
                        <option v-for="o in entryTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <hr>
        <h2>{{ $t('Endpoint') }}</h2>

<!-- In site-specific mode every site lists its own URL, so the base
             endpoint is hidden rather than shown as dead config. Its value
             is kept — flipping the switch back restores it untouched. -->
        <div class="field" v-if="!siteEndpointsMode" :class="{ 'has-errors': errors.endpoint?.length }">
            <div class="heading"><label>{{ $t('Base Endpoint') }}</label></div>
            <div class="instructions"><p v-html="$t('JSON URL, or an <code>@alias</code> pointing to a local JSON file.')"></p></div>
            <div class="input ltr">
                <tokenized-input
                    v-model="link.endpoint"
                    :token-groups="envSuggestions"
                    :disabled="readOnly"
                    placeholder="https://api.example.com/posts.json"
                    @blur="onEndpointBlur"
                />
            </div>
            <field-errors :messages="errors.endpoint" />
        </div>

        <div class="field" v-if="supportsMultiSite">
            <div class="heading"><label class="lightswitch-label">{{ $t('Site-specific endpoints') }}</label></div>
            <div class="instructions"><p>{{ $t('Enable if the external service supports resource localisation.') }}</p></div>
            <div class="input">
                <light-switch v-model="siteEndpointsMode" :disabled="readOnly" />
            </div>
        </div>

        <div class="field" v-if="supportsMultiSite && siteEndpointsMode" :class="{ 'has-errors': errors.siteEndpoints?.length }">
            <div class="instructions">
                <p>{{ $t('The link runs once per listed site and writes localized data to the same canonical element.') }}</p>
            </div>
            <site-endpoints-table v-model="link.siteEndpoints" :sites="options.sites" :token-groups="envSuggestions" :disabled="readOnly" />
            <field-errors :messages="errors.siteEndpoints" />
        </div>

        <div class="field">
            <div class="heading"><label class="lightswitch-label">{{ $t('Sliding-window presets') }}</label></div>
            <div class="instructions"><p>{{ $t('Enable if the external service supports synchronisation by offset.') }}</p></div>
            <div class="input">
                <light-switch v-model="supportsOffset" :disabled="readOnly" />
            </div>
        </div>

        <div class="field" v-if="supportsOffset">
            <div class="instructions">
                <p v-html="$t('Each preset becomes a button on the link page and a <code>--offset=KEY</code> option on the console command.')"></p>
            </div>
            <offset-presets-table v-model="link.offset" :disabled="readOnly" />
        </div>

        <div class="field">
            <div class="heading"><label class="lightswitch-label">{{ $t('Resource Endpoint supported') }}</label></div>
            <div class="input">
                <light-switch v-model="supportsItemEndpoint" :disabled="readOnly" />
            </div>
        </div>

        <div class="field" v-if="supportsItemEndpoint">
            <div class="heading"><label>{{ $t('Resource Endpoint') }}</label></div>
            <div class="instructions">
                <p>{{ $t('URL pattern for the per-element "Sync from remote" button. Type the URL and use the picker to inline a token where the cursor is — chips show you where each placeholder lives.') }}</p>
            </div>
            <div class="input ltr">
                <tokenized-input
                    v-model="link.itemEndpoint"
                    :token-groups="combinedSuggestions"
                    :disabled="readOnly"
                    placeholder="https://api.example.com/users/…"
                />
            </div>
        </div>

        <hr>
        <h2>{{ $t('Processing actions') }}</h2>

        <div class="field">
            <ul class="checkbox-group">
                <li v-for="opt in options.processingActions" :key="opt.value">
                    <input type="checkbox"
                           class="checkbox"
                           :id="`builder-processing-${opt.value}`"
                           :value="opt.value"
                           :checked="link.processing.includes(opt.value)"
                           :disabled="readOnly"
                           @change="toggleProcessing(opt.value, $event.target.checked)" />
                    <label :for="`builder-processing-${opt.value}`">{{ opt.label }}</label>
                    <p v-if="opt.note" class="influx-processing-note light">{{ opt.note }}</p>
                </li>
            </ul>
        </div>
    </div>
</template>

<script>
import { store } from '../store.js';
import TokenizedInput from '../TokenizedInput.vue';
import OffsetPresetsTable from '../OffsetPresetsTable.vue';
import SiteEndpointsTable from '../SiteEndpointsTable.vue';
import LightSwitch from '../LightSwitch.vue';
import FieldErrors from '../FieldErrors.vue';

export default {
    name: 'GeneralTab',

    components: { TokenizedInput, OffsetPresetsTable, SiteEndpointsTable, LightSwitch, FieldErrors },

    data() {
        return {
            // The reactive root from the store. Two-way bindings (v-model)
            // write straight back into the store via this reference.
            options: store.ui.options,
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        // allowAdminChanges is off: every control renders disabled so the
        // stored config stays inspectable but untouchable. The server-side
        // backstop is LinkBuilderController::actionSave()'s assertWriteable().
        readOnly() { return !!this.ui.meta?.readOnly; },

        // Store-owned so save() can require at least one site endpoint
        // while the switch is on, and sampling can prefer site URLs.
        siteEndpointsMode: {
            get() { return this.ui.siteEndpointsMode; },
            set(v) { store.setSiteEndpointsMode(v); },
        },

        // Also store-owned so a flip rides dirty-tracking and save() strips
        // the gated field from the outbound payload. The value stays in state
        // either way — flipping off just hides the editor.
        supportsItemEndpoint: {
            get() { return this.ui.supportsItemEndpoint; },
            set(v) { store.setSupportsItemEndpoint(v); },
        },

        supportsOffset: {
            get() { return this.ui.supportsOffset; },
            set(v) { store.setSupportsOffset(v); },
        },

        // The option bundle for the selected element type — carries the
        // capability flags (criteria keys, multi-site support) the target
        // reported. Undefined before an element type is chosen.
        currentElementType() {
            return (this.options.elementTypes || [])
                .find((o) => o.value === this.link.elementType);
        },

        // Non-localizable element types (Users) can't run per-site, so the
        // site-specific endpoint controls are hidden for them. Defaults to
        // true until an element type is resolved.
        supportsMultiSite() {
            return this.currentElementType ? this.currentElementType.multiSite !== false : true;
        },

        section: {
            get() { return this.link.elementCriteria.section || ''; },
            set(v) {
                this.link.elementCriteria = {
                    ...this.link.elementCriteria,
                    section: v || null,
                    type: null,
                };
            },
        },

        entryType: {
            get() { return this.link.elementCriteria.type || ''; },
            set(v) {
                this.link.elementCriteria = { ...this.link.elementCriteria, type: v || null };
            },
        },

        entryTypeOptions() {
            const map = this.options.sectionEntryTypes[this.section] || {};
            return Object.entries(map).map(([handle, label]) => ({ value: handle, label }));
        },

        // Validation errors keyed by attribute. Each input's `<field-errors>`
        // pulls its own slice; the parent watches the whole map to mark
        // affected tabs.
        errors() {
            return this.ui.errors || {};
        },

        // Env vars + Craft aliases — text-typed picker items shared by
        // both endpoint inputs (Base + Resource).
        envSuggestions() {
            return this.ui.meta?.envSuggestions || [];
        },

        // The Resource Endpoint picker gets both link-scoped chip tokens
        // ({id}, {slug}, custom-field handles…) and the env / alias text
        // snippets. One combined list keeps the picker UX consistent. The
        // token list itself is refetched by the store's criteria watcher.
        combinedSuggestions() {
            return [...(this.ui.tokenSuggestions || []), ...this.envSuggestions];
        },
    },

    mounted() {
        // While the link is unsaved (new or a duplicate), derive the handle
        // from the name using Craft's own HandleGenerator — the same widget
        // its section / entry-type editors use. It writes the handle field and
        // dispatches native `input` events, so v-model picks the value up, and
        // it stops itself the moment the user hand-edits the handle (and honours
        // the site's handle casing). An existing link keeps its saved handle,
        // and the post-save reload lands in edit mode, so the sync only ever
        // runs during the initial unsaved session.
        const Craft = window.Craft;

        if (this.ui.meta?.isNew && Craft?.HandleGenerator) {
            const name = this.$el.querySelector('#builder-name');
            const handle = this.$el.querySelector('#builder-handle');

            if (name && handle) {
                this._handleGenerator = new Craft.HandleGenerator(name, handle);
            }
        }
    },

    beforeUnmount() {
        if (this._handleGenerator) {
            this._handleGenerator.destroy();
            this._handleGenerator = null;
        }
    },

    methods: {
        // Whether the selected element type scopes on the given criteria key
        // (e.g. entries use 'section' / 'type'; users use none) — drives which
        // criteria dropdowns render.
        usesCriteria(key) {
            return (this.currentElementType?.criteria || []).includes(key);
        },

        // Switching element type invalidates the previous type's criteria and,
        // for a non-localizable type, the site-specific endpoint mode — reset
        // both so the link can't carry state the new type (and the server)
        // rejects.
        onElementTypeChange() {
            this.link.elementCriteria = {};

            if (!this.supportsMultiSite) {
                store.setSiteEndpointsMode(false);
            }
        },

        toggleProcessing(value, on) {
            const set = new Set(this.link.processing);
            on ? set.add(value) : set.delete(value);
            this.link.processing = Array.from(set);
        },

        // Auto-fetch the sample once the user is done editing the endpoint
        // — keyed in the store, so tabbing through without changes is free.
        onEndpointBlur() {
            store.autoFetchSample();
        },
    },
};
</script>
