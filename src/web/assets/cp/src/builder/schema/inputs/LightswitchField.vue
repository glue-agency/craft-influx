<template>
    <label class="inline-toggle">
        <input
            type="checkbox"
            :checked="!! modelValue"
            :disabled="readOnly"
            @change="$emit('update:modelValue', $event.target.checked)"
        >
        <!-- The label rides INSIDE the toggle in a layout that gives its controls
             no heading of their own (the mapping extras); one that does leaves the
             slot empty. -->
        <template v-if="inlineLabel">{{ node.label }}</template>
    </label>
</template>

<script>
/**
 * A boolean the operator flips — "create when not found", "download & upload
 * missing files", a user group's membership.
 *
 * `false` is a decision here, not an absence: a switch the operator turned off
 * reads back as off rather than falling through to the node's declared default.
 * The slot writer prunes it away all the same, which is why an off switch and an
 * untouched one look identical in Project Config.
 */
export default {
    name: 'LightswitchField',

    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [Boolean, String, Number, null], default: null },
        // Whether to render the node's label beside the toggle — see the template.
        inlineLabel: { type: Boolean, default: false },
        readOnly: { type: Boolean, default: false },
    },
};
</script>
