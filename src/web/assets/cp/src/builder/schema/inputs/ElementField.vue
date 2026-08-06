<template>
    <v-element-picker
        :model-value="modelValue"
        :element-type="node.elementType || 'craft\\elements\\Entry'"
        :field-handle="fieldHandle"
        @update:model-value="$emit('update:modelValue', $event)"
    />
</template>

<script>
import ElementPicker from '../../ElementPicker.vue';

/**
 * Craft's own element select, mounted server-side. The picked id (or list of
 * ids, for a multi-relation field) is the value; null on clear, which the slot
 * writer prunes away.
 *
 * `fieldHandle` shapes the picker after the field it belongs to — sources,
 * single vs multiple — which the server derives from the field itself rather
 * than trusting anything posted. A native attribute sends nothing: only a custom
 * field's handle is meaningful, and a real custom field handled `author` would
 * otherwise reshape the native author's picker.
 */
export default {
    name: 'ElementField',

    // The cell renderer binds one interface to every control, so a node this
    // one doesn't read must not land on its root element as an attribute.
    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number, Array, null], default: null },
        fieldHandle: { type: String, default: null },
        readOnly: { type: Boolean, default: false },
    },

    components: { 'v-element-picker': ElementPicker },
};
</script>
