<template>
    <form ref="form" method="get" :action="config.url" class="toolbar flex influx-logs-toolbar">
        <div v-for="filter in filters" :key="filter.name" class="influx-logs-filter">
            <span class="influx-logs-filter-label" v-text="filter.label"></span>

            <v-searchable-select
                :model-value="picked[filter.name]"
                :options="options(filter)"
                :placeholder="filter.placeholder"
                :aria-label="filter.ariaLabel"
                :searchable="filter.searchable"
                multiple
                @update:model-value="pick(filter.name, $event)"
            ></v-searchable-select>

            <!-- What the GET submit actually sends: one input per picked value,
                 so the browser builds `name[]=a&name[]=b` and the server reads
                 the filter as the list it is. -->
            <input
                v-for="value in picked[filter.name]"
                :key="value"
                type="hidden"
                :name="`${filter.name}[]`"
                :value="value"
            >
        </div>

        <button
            v-if="isFiltered"
            type="button"
            class="btn influx-logs-filters-clear"
            @click="clear"
            v-text="config.clearLabel"
        ></button>
    </form>
</template>

<script>
import SearchableSelect from '../components/SearchableSelect.vue';

/**
 * The Logs overview's filter toolbar: one multi-select per filter, applied by
 * submitting the surrounding GET form — so the list itself stays server
 * rendered and every filtered view remains a URL you can bookmark, link to,
 * and page through.
 *
 * Filters are declared by the host template rather than known here, which is
 * what keeps the labels where they are already translated: the visible label,
 * the accessible name, option labels, the placeholders and the clear button's
 * copy all arrive pre-translated in the config, and a fifth filter is a template
 * change with no Vue edit. The shape:
 *
 *   {
 *     url: '<the overview URL>',
 *     clearLabel: 'Clear filters',
 *     filters: [
 *       {name: 'link', label: 'Link', ariaLabel: 'Filter by link',
 *        placeholder: 'All', searchable: true,
 *        options: {units: 'Units'}, selected: ['units']},
 *       …
 *     ],
 *   }
 *
 * Applied on every pick, so the list answers the filter as it is built rather
 * than once the operator has moved on. A pick that changes nothing doesn't
 * submit, so opening a menu to look costs no page load.
 */
export default {
    name: 'LogFilters',

    props: {
        config: { type: Object, required: true },
    },

    data() {
        return {
            // The draft set per filter, seeded from what the URL asked for.
            picked: Object.fromEntries(
                (this.config.filters || []).map(filter => [filter.name, [...(filter.selected || [])]]),
            ),
        };
    },

    computed: {
        filters() {
            return this.config.filters || [];
        },

        isFiltered() {
            return this.filters.some(filter => this.values(filter.name).length > 0);
        },

        /**
         * Whether the draft differs from what the page was rendered for. Set
         * comparison, not array: picks accumulate in click order, so the same
         * selection can arrive in a different order than the server listed it.
         */
        changed() {
            return this.filters.some((filter) => {
                const applied = filter.selected || [];
                const draft = this.values(filter.name);

                if (applied.length !== draft.length) {
                    return true;
                }

                return draft.some(value => ! applied.includes(value));
            });
        },
    },

    methods: {
        values(name) {
            return this.picked[name] || [];
        },

        /**
         * A filter's options arrive as the `value: label` map the server already
         * had (a link's handle to its name, an enum's value to its label) rather
         * than as pairs — the select wants pairs.
         */
        options(filter) {
            return Object.entries(filter.options || {}).map(([value, label]) => ({ value, label }));
        },

        /**
         * A multi-select answers with null once its last value is taken back
         * out, which for a filter means "all" rather than "no value".
         */
        pick(name, value) {
            this.picked[name] = Array.isArray(value) ? value : [];
            this.apply();
        },

        apply() {
            if (! this.changed) {
                return;
            }

            this.submit();
        },

        clear() {
            for (const filter of this.filters) {
                this.picked[filter.name] = [];
            }

            this.submit();
        },

        /**
         * Submit on the next tick so the hidden inputs have re-rendered from
         * the draft — they, not this component, are what the browser sends.
         * The action URL carries no `page`, so any filter change lands on page
         * one rather than page seven of a shorter list.
         */
        submit() {
            this.$nextTick(() => this.$refs.form.submit());
        },
    },

    components: { 'v-searchable-select': SearchableSelect },
};
</script>
