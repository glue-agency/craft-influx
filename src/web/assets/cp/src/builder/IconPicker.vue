<template>
    <div ref="host" class="influx-icon-host" />
</template>

<script>
import * as api from './api.js';

/**
 * Thin Vue host for Craft's native icon picker — the sibling of
 * {@see ElementPicker}, and the same trade: a server round-trip for the real CP
 * control instead of a Vue re-implementation of it.
 *
 * Worth it more here than anywhere. Craft's icon set is some 3,800 entries, each
 * with its own search terms and a Pro flag, and Craft already searches them
 * SERVER-side from inside this control (`app/icon-picker-options`, which is why
 * "car" finds "automobile"). A select over icon names would ship all of them to
 * do a worse job, with no icon to look at.
 *
 * The SPA fetches the rendered markup, drops it into a ref'd container, and from
 * there `Craft.IconPicker` owns the inner DOM — Vue never reaches inside. We
 * only:
 *   - re-render when the bound value changes externally (store reload, save);
 *   - listen for the picker's `change` event to emit `update:modelValue`;
 *   - destroy the instance before re-rendering or unmounting.
 *
 * Whether Pro icons are selectable is the FIELD's setting, so it's the server's
 * call from `fieldHandle` — this component doesn't know what `includeProIcons`
 * is, and shouldn't, exactly as it doesn't know an Entries field's maxRelations.
 */
export default {
    name: 'IconPicker',

    emits: ['update:modelValue'],

    props: {
        // The bare Font Awesome name Craft stores, e.g. `user`.
        modelValue: { type: [String, null], default: null },
        // Handle of the custom field this default belongs to; shapes Pro gating.
        fieldHandle: { type: String, default: null },
    },

    data() {
        return {
            instance: null,
            // The render key we last rendered for; comparing it against the
            // current one tells us when a re-render is due.
            renderedFor: null,
        };
    },

    computed: {
        renderKey() {
            return `${this.fieldHandle ?? ''}|${this.modelValue ?? ''}`;
        },
    },

    watch: {
        renderKey(next) {
            if (next === this.renderedFor) return;
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
            if (! Craft?.IconPicker) {
                console.warn('[influx] Craft.IconPicker not available');
                return;
            }

            let payload;
            try {
                payload = await api.renderIconPicker(this.fieldHandle, this.modelValue);
            } catch (e) {
                console.error('[influx] render-icon-picker failed', e);
                return;
            }

            this.destroyInstance();
            this.$refs.host.innerHTML = payload.html;
            this.renderedFor = this.renderKey;

            // Craft.IconPicker takes a SELECTOR, not the settings object
            // BaseElementSelectInput takes — hence the id riding in jsSettings.
            this.instance = new Craft.IconPicker(
                `#${payload.jsSettings.id}`,
                { freeOnly: payload.jsSettings.freeOnly },
            );

            // One event covers both picking and removing: `iconName` is null for
            // a removal, which is what clears the stored default.
            this.instance.on('change', this.onChange);
        },

        onChange(e) {
            const next = e?.iconName || null;

            if (next === (this.modelValue ?? null)) return;

            // Craft owns the DOM this selection came from — re-rendering it would
            // be pointless churn, so bank the key as already rendered.
            this.renderedFor = `${this.fieldHandle ?? ''}|${next ?? ''}`;
            this.$emit('update:modelValue', next);
        },
    },
};
</script>
