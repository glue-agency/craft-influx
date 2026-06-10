<template>
    <div class="influx-tab-mapping">
        <p class="light">{{ $t('For each destination field, pick the JSON node it should read from. Add a default value to fall back to when the node is missing or empty. Use the “Fetch sample” button on the Pagination tab to populate the dropdowns with discovered JSON nodes.') }}</p>

        <div v-if="state.mappableError" class="influx-sample-error">
            <strong>{{ $t('Couldn’t load mappable fields:') }}</strong> {{ state.mappableError }}
        </div>

        <p v-if="state.loading || !state.mappable" class="light">
            {{ $t('Loading mappable fields…') }}
        </p>

        <p v-else-if="!state.mappable.fields.length" class="light">
            {{ $t('Pick an element type (and a section, for entries) on the General tab to see destination fields here.') }}
        </p>

        <template v-else>
            <div class="influx-mapping-list">
                <mapping-group
                    v-for="(group, idx) in state.mappable.groups"
                    :key="group.label + ':' + idx"
                    :group="group"
                    :node-options="nodeOptions"
                />
            </div>

            <h2 style="margin-top:24px;">{{ $t('Match key') }}</h2>
            <p class="light">{{ $t('How Influx pairs a remote item with an existing element across sites. The match value is read from the field’s configured source node above, so make sure that field has a mapping.') }}</p>

            <div class="field" :class="{ 'has-errors': state.errors.match?.length }">
                <div class="heading"><label for="builder-match-attribute">{{ $t('Match attribute') }} <span class="required">*</span></label></div>
                <div class="instructions">
                    <p v-html="$t('The element field whose value uniquely identifies a record across syncs. Typically a custom plain-text field like <code>importId</code>.')"></p>
                </div>
                <div class="input ltr">
                    <searchable-select
                        v-model="matchAttribute"
                        :options="state.mappable.matchOptions"
                        :placeholder="$t('Select an attribute…')"
                        :search-placeholder="$t('Search attributes…')"
                    />
                </div>
                <field-errors :messages="state.errors.match" />
            </div>
        </template>
    </div>
</template>

<script>
import MappingGroup from './MappingGroup.vue';
import FieldErrors from '../FieldErrors.vue';
import SearchableSelect from '../SearchableSelect.vue';
import { store } from '../store.js';
import { mergeNodeOptions } from '../lib/mappings.js';

/**
 * Orchestrates the Mapping tab: lazy-loads the mappable-fields tree from
 * the server (and refreshes it when section / entry-type change), feeds
 * each group into a MappingGroup component, and renders the Match-key
 * dropdown beneath the list.
 *
 * The source-node candidates come from the latest Fetch sample (run on
 * the Pagination tab). Without a sample, the dropdowns are empty except
 * for any previously-saved value the user could clear back to.
 */
export default {
    name: 'MappingTab',

    components: { MappingGroup, FieldErrors, SearchableSelect },

    data() {
        return {
            link: store.raw.link,
            state: store.state,
        };
    },

    computed: {
        // Stable identifier for the criteria signature. Watched below so
        // a section or entry-type change refetches the mappable-fields
        // tree without re-rendering the rest of the SPA.
        criteriaSignature() {
            const c = this.link.elementCriteria || {};
            return `${this.link.elementType}|${c.section || ''}|${c.type || ''}`;
        },

        nodeOptions() {
            // Merge saved-but-not-discovered mapping nodes so a row whose
            // node fell out of the sample still has a legible selected
            // option. The row-level missing badge (driven by isMissing in
            // MappingRow) tells the user the node isn't in the latest
            // sample — the dropdown itself stays a plain picker.
            const mappings = this.link.mappings || {};
            const savedNodes = Object.keys(mappings)
                .map(handle => mappings[handle].node)
                .filter(Boolean);
            return mergeNodeOptions(store.state.sample?.flatNodes ?? [], savedNodes);
        },

        matchAttribute: {
            get() { return this.link.match?.attribute || ''; },
            set(v) {
                this.link.match = v ? { attribute: v } : {};
            },
        },
    },

    watch: {
        criteriaSignature: {
            immediate: true,
            handler() {
                store.refreshMappableFields();
            },
        },
    },
};
</script>
