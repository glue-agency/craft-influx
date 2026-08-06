<template>
    <v-icon-picker
        :model-value="modelValue ?? null"
        :field-handle="fieldHandle"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>

<script>
import IconPicker from '../../IconPicker.vue';

/**
 * Craft's own icon picker, mounted server-side for the same reason
 * {@see ElementField} mounts its element select: the icon set is thousands of
 * entries with their own search terms and Pro gating, all of which Craft already
 * searches server-side.
 *
 * Nothing about the field's settings rides the node — `fieldHandle` is what lets
 * the server derive Pro gating, the way it derives an element picker's sources.
 */
export default {
    name: 'IconField',

    // The cell renderer binds one interface to every control, so a node this
    // one doesn't read must not land on its root element as an attribute.
    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, null], default: null },
        fieldHandle: { type: String, default: null },
        readOnly: { type: Boolean, default: false },
    },

    components: { 'v-icon-picker': IconPicker },
};
</script>
