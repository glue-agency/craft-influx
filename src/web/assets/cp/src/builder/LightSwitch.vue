<template>
    <button
        type="button"
        role="switch"
        class="lightswitch"
        :class="{ on: modelValue, small: small }"
        :aria-checked="modelValue ? 'true' : 'false'"
        :disabled="disabled"
    >
        <div class="lightswitch-container">
            <div class="handle"></div>
        </div>
    </button>
</template>

<script>
/**
 * Thin Vue wrapper around Craft's `Craft.LightSwitch`. Renders the canonical
 * CP markup (`<button class="lightswitch">` + `.lightswitch-container > .handle`)
 * and hands the element to Craft's jQuery plugin so we get the same drag
 * gesture, focus ring, keyboard activation, and screen-reader plumbing as
 * any other CP lightswitch — instead of re-inventing a `.on` class toggle.
 *
 * `Craft.LightSwitch` triggers a jQuery `change` event when toggled. We
 * listen for it and emit `update:modelValue`, so the component plugs into
 * a regular `v-model="link.someFlag"` binding from the parent.
 *
 * If the parent updates the bound value externally (e.g. on a store
 * reload), the `modelValue` watcher calls `ls.turnOn()` / `ls.turnOff()`
 * so the visual stays in sync with the data.
 */
export default {
    name: 'LightSwitch',

    props: {
        modelValue: { type: Boolean, default: false },
        small:      { type: Boolean, default: false },
        disabled:   { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    mounted() {
        const $ = window.jQuery;
        if (!$ || !$.fn.lightswitch) return;
        const $el = $(this.$el);
        $el.lightswitch();
        $el.on('change.influxLs', () => {
            const next = $el.hasClass('on');
            if (next !== this.modelValue) {
                this.$emit('update:modelValue', next);
            }
        });
    },

    beforeUnmount() {
        const $ = window.jQuery;
        if (!$) return;
        const $el = $(this.$el);
        $el.off('change.influxLs');
        const ls = $el.data('lightswitch');
        if (ls && typeof ls.destroy === 'function') ls.destroy();
    },

    watch: {
        modelValue(next) {
            const $ = window.jQuery;
            if (!$) return;
            const ls = $(this.$el).data('lightswitch');
            if (!ls) return;
            if (next && !ls.on)       ls.turnOn();
            else if (!next && ls.on)  ls.turnOff();
        },
    },
};
</script>
