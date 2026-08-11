<template>
    <!-- The plain CP select, for a layout that asks for one: a stacked Craft
         `.field` block, where the native control IS the idiom. Never for a grouped
         list, which needs the group headings only SearchableSelect renders. -->
    <div v-if="native && ! grouped" class="select">
        <select
            :value="modelValue ?? ''"
            :disabled="readOnly"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <option v-for="opt in resolvedOptions" :key="opt.value" :value="opt.value" v-text="opt.label"></option>
        </select>
    </div>

    <v-searchable-select
        v-else
        :model-value="modelValue ?? (multiple ? null : '')"
        :options="resolvedOptions"
        :multiple="multiple"
        :searchable="searchable"
        :allow-custom="!! node.allowCustom"
        :empty-is-value="emptyIsValue"
        :placeholder="placeholder"
        :search-placeholder="node.searchPlaceholder || ''"
        :empty-label="loading ? $t('Loading…') : (node.emptyLabel || '')"
        :disabled="readOnly"
        @open="loadLazyOptions"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>

<script>
import SearchableSelect from '../../../components/SearchableSelect.vue';
import * as api from '../../api.js';

/**
 * Every select a schema declares, in every region — the control described by its
 * node, so there is one component behind `select` and `multiSelect` rather than a
 * branch per place a select appears.
 *
 * `multiSelect` is its own node TYPE rather than a flag, because the arity changes
 * what the stored value IS: a list, for the option fields that hold one
 * (Checkboxes, MultiSelect). Both types land here.
 *
 * The node says what the control IS and what it offers; the props say how the
 * REGION around it presents one. So `native` / `emptyIsValue` / `placeholder`
 * arrive from the renderer — a stacked Craft field wants the plain select and a
 * mapping extra wants `—` standing for "nothing picked" — while `allowCustom`,
 * `searchable` and the option list stay the node's business.
 *
 * Two things the node can't carry, and so arrive as props too. `options` may need
 * client state merged in (the source cell's discovered sample nodes), which only
 * the renderer has. And a `lazy` node ships no options at all: the list is fetched
 * on first open, for lists big enough that every builder bootstrap would otherwise
 * pay for them whether or not the row is ever opened — every country in the world,
 * per Country field on the layout. A failed fetch leaves the list empty rather
 * than retrying: the row still takes a typed value through its own node cell, and
 * the console carries the reason.
 */
export default {
    name: 'SelectField',

    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number, Array, null], default: null },
        // Options to render instead of the node's own — the source-node cell
        // passes the sample's discovered nodes merged under its sentinel rows.
        options: { type: Array, default: null },
        // Whose default-options endpoint answers a `lazy` node. Null for a
        // native attribute, which has no field to resolve.
        fieldHandle: { type: String, default: null },
        // Render the plain CP select instead of the searchable one.
        native: { type: Boolean, default: false },
        // Treat value='' as a real, labeled choice (the date format's
        // "Auto-detect") rather than the no-selection sentinel.
        emptyIsValue: { type: Boolean, default: false },
        placeholder: { type: String, default: '' },
        readOnly: { type: Boolean, default: false },
    },

    data() {
        return {
            // A lazily-declared list, once fetched. Null until then — also the
            // "not fetched yet" flag, so it can't refetch per open.
            lazyOptions: null,
            loading: false,
        };
    },

    computed: {
        multiple() {
            return this.node.type === 'multiSelect';
        },

        /** Grouped `[{label, kind?, options}]` rather than a flat `[{value, label}]`. */
        grouped() {
            return Array.isArray(this.resolvedOptions[0]?.options);
        },

        /**
         * The search box: whatever the node asked for, and always for a grouped
         * list — a match-by list spanning an element's attributes AND its layout's
         * fields is the case the box exists for, whichever region it renders in.
         */
        searchable() {
            return !! this.node.searchable || this.grouped;
        },

        /**
         * A lazy node ships no options, so its fetched list is the whole list;
         * otherwise the renderer's list wins over the node's own, for the source
         * cell whose rows are client state.
         *
         * Sentinels then go on top of whichever it was — including the lazy list,
         * whose "— no default —" has to be pickable before the fetch resolves (and
         * whose endpoint therefore returns none of its own).
         */
        resolvedOptions() {
            const supplied = this.node.lazy
                ? (this.lazyOptions || [])
                : (this.options ?? this.node.options ?? []);

            return this.node.sentinelOptions ? this.withSentinels(supplied) : supplied;
        },
    },

    methods: {
        /**
         * The node's own sentinel rows up top as a plain group, with the supplied
         * options beneath them under the heading the node names — the source cell,
         * whose "— no mapping —" / "— use default —" rows are declared but whose
         * feed nodes can only ever be assembled here.
         */
        withSentinels(supplied) {
            const groups = [{ label: null, kind: null, options: this.node.sentinelOptions }];

            if (supplied.length) {
                groups.push({
                    label: this.node.optionsLabel || null,
                    kind: this.node.optionsKind || null,
                    options: supplied,
                });
            }

            return groups;
        },

        async loadLazyOptions() {
            if (! this.node.lazy || this.lazyOptions || this.loading || ! this.fieldHandle) {
                return;
            }

            this.loading = true;

            try {
                const payload = await api.defaultOptions(this.fieldHandle);
                this.lazyOptions = payload.options || [];
            } catch (e) {
                console.error('[influx] default-options failed', e);
            } finally {
                this.loading = false;
            }
        },
    },

    components: { 'v-searchable-select': SearchableSelect },
};
</script>
