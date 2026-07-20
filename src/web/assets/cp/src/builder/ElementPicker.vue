<template>
    <div ref="host" class="influx-element-host"></div>
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
 */
export default {
    name: 'ElementPicker',

    props: {
        modelValue:  { type: [String, Number, null], default: null },
        elementType: { type: String, required: true },
    },

    emits: ['update:modelValue'],

    data() {
        return {
            instance: null,
            // String form of whatever id we last rendered for — comparing
            // against the next modelValue tells us when to re-render.
            renderedFor: '',
        };
    },

    watch: {
        modelValue(next) {
            const want = next == null ? '' : String(next);
            if (want === this.renderedFor) return;
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
        destroyInstance() {
            if (this.instance && typeof this.instance.destroy === 'function') {
                try { this.instance.destroy(); } catch (_) { /* swallow — Craft is forgiving here */ }
            }
            this.instance = null;
        },

        async renderAndInit() {
            const Craft = window.Craft;
            if (!Craft?.BaseElementSelectInput) {
                console.warn('[influx] Craft.BaseElementSelectInput not available');
                return;
            }

            const ids = this.modelValue ? [this.modelValue] : [];
            let payload;
            try {
                payload = await api.renderElementSelect(this.elementType, ids);
            } catch (e) {
                console.error('[influx] render-element-select failed', e);
                return;
            }

            this.destroyInstance();
            this.$refs.host.innerHTML = payload.html;
            this.renderedFor = this.modelValue == null ? '' : String(this.modelValue);

            this.instance = new Craft.BaseElementSelectInput(payload.jsSettings);
            // BaseElementSelectInput triggers these on its own jQuery wrapper.
            // We don't care which fired — we just read the current id set.
            this.instance.on('selectElements', this.syncFromInstance);
            this.instance.on('removeElements', this.syncFromInstance);
        },

        syncFromInstance() {
            if (!this.instance) return;
            let ids = [];
            try { ids = this.instance.getSelectedElementIds() ?? []; }
            catch (_) { ids = []; }
            const next = ids[0] != null ? String(ids[0]) : null;
            const current = this.modelValue == null ? null : String(this.modelValue);
            if (next === current) return;
            this.renderedFor = next ?? '';
            this.$emit('update:modelValue', next);
        },
    },
};
</script>
