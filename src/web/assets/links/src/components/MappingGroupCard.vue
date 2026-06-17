<template>
    <div class="influx-mapping-group"
         :class="[variantClass, { collapsed: collapsible && !isExpanded, 'is-static': !collapsible }]">
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
                <span class="label">{{ label }}</span>
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
 * Used by the builder's MappingGroup + ElementSubFields (collapsible, pills)
 * and the debug/log DebugItem (static, custom header). Keeping the chrome here
 * — and emitting the same `influx-mapping-group*` class names — lets every
 * consumer share one implementation, and lets SchemaForm's sub-field subgrid
 * rules keep matching by class name.
 */
export default {
    name: 'MappingGroupCard',

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
                this.isExpanded = !this.isExpanded;
            }
        },
    },
};
</script>

<style>
/* Unscoped on purpose: the pills live in consumers' `tags` slots (parent
   scope) and SchemaForm reaches this card's body, so scoped styles wouldn't
   reach either. Class-namespaced under .influx-mapping-group instead. */
.influx-mapping-group {
    margin-bottom: 12px;
    border: 1px solid rgba(0, 0, 0, .1);
    border-radius: 5px;
    /* No overflow:hidden — it clipped absolutely-positioned dropdowns opening
       from rows near the card's edge. Corners are rounded on the first/last
       children instead. */
    background: #fff;
}

.influx-mapping-group-header { border-radius: 4px 4px 0 0; }
.influx-mapping-group.collapsed .influx-mapping-group-header { border-radius: 4px; }
.influx-mapping-group .influx-mapping-row:last-child { border-radius: 0 0 4px 4px; }

.influx-mapping-group-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f3f5f7;
    border-bottom: 1px solid rgba(0, 0, 0, .08);
    font-weight: 600;
    cursor: pointer;
    user-select: none;
}

.influx-mapping-group-header:hover { background: #ebeef2; }

/* Static (non-collapsible) header — informational, no toggle affordance. */
.influx-mapping-group.is-static .influx-mapping-group-header {
    cursor: default;
    flex-wrap: wrap;
}
.influx-mapping-group.is-static .influx-mapping-group-header:hover { background: #f3f5f7; }

.influx-mapping-group-header .chevron {
    display: inline-block;
    transition: transform .15s ease;
    color: #888;
    font-size: 11px;
}

.influx-mapping-group.collapsed .chevron { transform: rotate(-90deg); }

.influx-mapping-group-header .label { flex: 1 1 auto; }

.influx-mapping-group-header .pill {
    border-radius: 9px;
    padding: 1px 7px;
    font-size: 11px;
    font-weight: 600;
}

.influx-mapping-group-header .pill-count {
    background: rgba(0, 0, 0, .08);
    color: #444;
}

.influx-mapping-group-header .pill-mapped {
    background: #d6f1de;
    color: #064f1f;
    border: 1px solid #7fcb95;
}

.influx-mapping-group-header .pill-mapped[data-mapped="0"] {
    background: #eee;
    color: #888;
    border-color: #ddd;
}

.influx-mapping-group-header .pill-auto {
    background: #fff7d6;
    color: #8a6d00;
    border: 1px solid #f0d97a;
}

.influx-mapping-group-header .pill-missing {
    background: #fdecc8;
    color: #8a6d00;
    border: 1px solid #f0c674;
}

.influx-mapping-group.collapsed .influx-mapping-group-body { display: none; }
</style>
