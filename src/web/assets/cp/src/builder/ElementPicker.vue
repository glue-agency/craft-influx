<template>
    <div ref="host" class="influx-element-host" />
</template>

<script>
import * as api from './api.js';

/**
 * Thin Vue host for Craft's native element select.
 *
 * The SPA fetches Craft's server-rendered `forms/elementSelect` markup
 * (including any currently-selected element chips) and drops the HTML
 * into a Vue ref'd container. From that point on, `Craft.BaseElementSelectInput`
 * owns the inner DOM — Vue does not reach inside. We only:
 *   - re-render when the bound value changes externally (store reload, save);
 *   - listen for Craft's `selectElements`/`removeElements` events to emit
 *     `update:modelValue` back to the store;
 *   - destroy the instance before re-rendering or unmounting.
 *
 * This trades a server round-trip for visual consistency: users see the
 * exact same chip + "Choose element" button they get on every other CP
 * field, including thumbnails, status dots, drag handles, and the full
 * element selector modal — no Vue re-implementation of any of it.
 *
 * The picker's SHAPE — which sources it offers, and whether it holds one
 * element or a list — is the server's call, derived from the mapped field
 * (LinkBuilderService::elementSelectConfigFor()). Hence `fieldHandle` going
 * out and `single` coming back: this component doesn't know what an Entries
 * field's maxRelations is, and shouldn't.
 */
export default {
    name: 'ElementPicker',

    emits: ['update:modelValue'],

    props: {
        // A single-element picker binds a bare id, a multi-element one the
        // list of picked ids.
        modelValue:  { type: [String, Number, Array, null], default: null },
        elementType: { type: String, required: true },
        // Handle of the CUSTOM field this default belongs to, or null for a
        // native row — see MappingRow for why natives send nothing.
        fieldHandle: { type: String, default: null },
    },

    data() {
        return {
            instance: null,
            // Whether the server rendered a one-element picker. Until a payload
            // says otherwise, assume it did — that's the historical shape.
            single: true,
            // The render key we last rendered for; comparing it against the
            // current one tells us when a re-render is due.
            renderedFor: '',
        };
    },

    computed: {
        // The bound value as the id list the endpoint takes, whichever shape
        // the picker stores in.
        ids() {
            const value = Array.isArray(this.modelValue) ? this.modelValue : [this.modelValue];

            return value.filter(id => id != null && id !== '').map(String);
        },

        renderKey() {
            return this.keyFor(this.ids);
        },
    },

    watch: {
        renderKey(next) {
            if (next === this.renderedFor) return;
            this.renderAndInit();
        },
        elementType() {
            this.renderAndInit();
        },
    },

    mounted() {
        this.renderAndInit();
    },

    beforeUnmount() {
        this.destroyInstance();
    },

    methods: {
        // A re-render is due when the picked ids change OR the field they're
        // picked for does — the field decides the picker's sources and limit.
        keyFor(ids) {
            return `${this.fieldHandle ?? ''}|${ids.join(',')}`;
        },

        destroyInstance() {
            if (this.instance && typeof this.instance.destroy === 'function') {
                try { this.instance.destroy(); } catch (_) { /* swallow — Craft is forgiving here */ }
            }
            this.instance = null;
        },

        async renderAndInit() {
            const Craft = window.Craft;
            if (! Craft?.BaseElementSelectInput) {
                console.warn('[influx] Craft.BaseElementSelectInput not available');
                return;
            }

            const ids = this.ids;
            let payload;
            try {
                payload = await api.renderElementSelect(this.elementType, ids, this.fieldHandle);
            } catch (e) {
                console.error('[influx] render-element-select failed', e);
                return;
            }

            this.destroyInstance();
            this.$refs.host.innerHTML = payload.html;
            this.renderedFor = this.keyFor(ids);
            this.single = payload.jsSettings?.single !== false;

            this.instance = new Craft.BaseElementSelectInput(payload.jsSettings);
            // BaseElementSelectInput triggers these on its own jQuery wrapper.
            // We don't care which fired — we just read the current id set.
            this.instance.on('selectElements', this.syncFromInstance);
            this.instance.on('removeElements', this.syncFromInstance);
        },

        syncFromInstance() {
            if (! this.instance) return;
            let ids = [];
            try { ids = this.instance.getSelectedElementIds() ?? []; }
            catch (_) { ids = []; }
            ids = ids.filter(id => id != null && id !== '').map(String);

            const key = this.keyFor(ids);
            if (key === this.renderKey) return;

            // A one-element picker keeps emitting the bare id every default
            // saved so far is stored as; a multi one emits the list — but never
            // an EMPTY list, which the mapping would read as a default that is
            // set (FieldMapping::usesDefault()).
            const next = this.single ? (ids[0] ?? null) : (ids.length ? ids : null);

            // Craft owns the DOM this selection came from — re-rendering it
            // would be pointless churn, so bank the key as already rendered.
            this.renderedFor = key;
            this.$emit('update:modelValue', next);
        },
    },
};
</script>
