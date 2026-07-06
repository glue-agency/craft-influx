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
                    <select id="builder-elementType" v-model="link.elementType" :disabled="readOnly">
                        <option v-for="o in options.elementTypes" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>
            <field-errors :messages="errors.elementType" />
        </div>

        <div class="field">
            <div class="heading"><label for="builder-section">{{ $t('Section') }}</label></div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-section" v-model="section" :disabled="readOnly">
                        <option v-for="o in options.sections" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="field">
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

        <div class="field">
            <div class="heading"><label class="lightswitch-label">{{ $t('Site-specific endpoints') }}</label></div>
            <div class="instructions"><p>{{ $t('Enable if the external service supports resource localisation.') }}</p></div>
            <div class="input">
                <light-switch v-model="siteEndpointsMode" :disabled="readOnly" />
            </div>
        </div>

        <div class="field" v-if="siteEndpointsMode" :class="{ 'has-errors': errors.siteEndpoints?.length }">
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
            // Local UI flags mirroring the Twig form's lightswitches —
            // flipped off doesn't clear the underlying value, just hides
            // the editor so the user can iterate without losing config.
            // (The site-endpoints flag lives in the store instead: save()
            // validates against it and sampling keys off it.)
            supportsItemEndpoint:  !!store.link.itemEndpoint,
            supportsOffset:        Object.keys(store.link.offset || {}).length > 0,
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

    methods: {
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
