<template>
    <div class="influx-tab-mapping">
        <div v-if="ui.mappableError" class="influx-sample-error">
            <strong>{{ $t('Couldn’t load mappable fields:') }}</strong> {{ ui.mappableError }}
        </div>

        <!-- The list's own shape while it loads, rather than a line of text
             where the tree will be. Also covers a REFETCH (a changed section or
             entry type), where the previous element type's tree would otherwise
             sit there looking settled. -->
        <v-mapping-skeleton v-if="ui.loading || ui.mappableLoading || ! ui.mappable" />

        <p v-else-if="! ui.mappable.fields.length" class="light" v-text="$t('Pick an element type (and a section, for entries) on the General tab to see destination fields here.')"></p>

        <template v-else>
            <div class="influx-mapping-list">
                <v-mapping-group
                    v-for="(group, idx) in ui.mappable.groups"
                    :key="group.label + ':' + idx"
                    :group="group"
                    :node-options="nodeOptions"
                />
            </div>

            <template v-if="requiresMatch">
                <hr>
                <h2 v-text="$t('Match key')"></h2>

                <div class="field" :class="{ 'has-errors': ui.errors.match?.length }">
                    <div class="heading"><label for="builder-match-attribute">{{ $t('Match attribute') }} <span class="influx-required" aria-hidden="true">*</span></label></div>
                    <div class="input ltr">
                        <v-searchable-select
                            v-model="matchAttribute"
                            :options="ui.mappable.matchOptions"
                            :disabled="readOnly"
                            searchable
                            :placeholder="$t('Select an attribute…')"
                            :search-placeholder="$t('Search attributes…')"
                        />
                    </div>
                    <v-field-errors :messages="ui.errors.match" />
                </div>
            </template>
        </template>
    </div>
</template>

<script>
import MappingGroup from './MappingGroup.vue';
import MappingSkeleton from './MappingSkeleton.vue';
import FieldErrors from '../FieldErrors.vue';
import SearchableSelect from '../../components/SearchableSelect.vue';
import { store } from '../store.js';
import { mergeNodeOptions } from '../lib/mappings.js';

/**
 * Orchestrates the Mapping tab: lazy-loads the mappable-fields tree from
 * the server (and refreshes it when section / entry-type change), feeds
 * each group into a MappingGroup component, and renders the Match-key
 * dropdown beneath the list.
 *
 * The source-node candidates come from the latest Fetch sample (the header
 * action). Without a sample, the dropdowns are empty except
 * for any previously-saved value the user could clear back to.
 */
export default {
    name: 'MappingTab',

    data() {
        return {
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        readOnly() { return !!this.ui.meta?.readOnly; },

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
            return mergeNodeOptions(store.ui.sample?.flatNodes ?? [], savedNodes);
        },

        /**
         * Whether this link identifies its element by a match value — a Global Set
         * link, or an Entry link scoped to a Single, doesn't: its criteria already
         * name the one element.
         *
         * Comes with the mappable-fields response rather than with the element-type
         * capability flags, because for entries the answer depends on the section —
         * and that response is what already refetches when the criteria change.
         * Same tolerant default as GeneralTab's flags: absent reads as true, so an
         * older payload keeps showing the control.
         */
        requiresMatch() {
            return this.ui.mappable?.requiresMatch !== false;
        },

        matchAttribute: {
            get() { return this.link.match?.attribute || ''; },
            set(v) {
                this.link.match = v ? { attribute: v } : {};
            },
        },
    },

    components: { 'v-mapping-group': MappingGroup, 'v-mapping-skeleton': MappingSkeleton, 'v-field-errors': FieldErrors, 'v-searchable-select': SearchableSelect },
};
</script>
