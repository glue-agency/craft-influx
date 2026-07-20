<template>
    <div class="influx-mapping-group"
         :class="[variantClass, { collapsed: collapsible && ! isExpanded, 'is-static': ! collapsible }]">
        <div class="influx-mapping-group-header"
             :role="collapsible ? 'button' : null"
             :tabindex="collapsible ? 0 : null"
             :aria-expanded="collapsible ? (isExpanded ? 'true' : 'false') : null"
             @click="toggle"
             @keydown.enter.prevent="toggle"
             @keydown.space.prevent="toggle">
            <!-- Whole-header override (debug uses this); the default lays out
                 a chevron + label + a `tags` slot for right-side pills. -->
            <slot name="header" :expanded="isExpanded" :toggle="toggle">
                <span v-if="collapsible" class="chevron" aria-hidden="true">▼</span>
                <span class="label" v-text="label"></span>
                <slot name="tags" />
            </slot>
        </div>

        <div class="influx-mapping-group-body">
            <slot />
        </div>
    </div>
</template>

<script>
/**
 * The shared "mapping group" card chrome: a white card with a header bar
 * (optionally a collapse toggle) and a body. Consumers fill the header's
 * right side via the `tags` slot (pills, counts), or replace the whole header
 * via the `header` slot, and the body via the default slot.
 *
 * Used by the builder's MappingGroup + SubFieldRows (collapsible, pills).
 * Keeping the chrome here — and emitting the same `influx-mapping-group*` class
 * names — lets every consumer share one implementation, and lets SchemaForm's
 * sub-field subgrid rules keep matching by class name.
 */
export default {
    name: 'MappingGroupCard',

    // Emitted with the new expanded state on every toggle, so consumers can
    // react (e.g. the log viewer lazy-loads an item's detail on first expand).
    emits: ['toggle'],

    props: {
        label: { type: String, default: '' },
        collapsible: { type: Boolean, default: true },
        defaultExpanded: { type: Boolean, default: true },
        // '' | 'subfields' | 'debug' — adds the matching legacy class so
        // existing variant CSS (and SchemaForm's subgrid) keeps matching.
        variant: { type: String, default: '' },
    },

    data() {
        return {
            isExpanded: this.defaultExpanded,
        };
    },

    computed: {
        variantClass() {
            return {
                'influx-subfields-group': this.variant === 'subfields',
                'influx-debug-item': this.variant === 'debug',
            };
        },
    },

    methods: {
        toggle() {
            if (this.collapsible) {
                this.isExpanded = ! this.isExpanded;
                this.$emit('toggle', this.isExpanded);
            }
        },
    },
};
</script>
