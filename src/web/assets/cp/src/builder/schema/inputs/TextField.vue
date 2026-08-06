<template>
    <input type="text"
           class="text fullwidth"
           :class="{ code: node.type === 'code' }"
           :value="modelValue ?? ''"
           :placeholder="node.placeholder || ''"
           :disabled="readOnly"
           @input="$emit('update:modelValue', $event.target.value)" />
</template>

<script>
/**
 * The registry's fallback control, and so the one every unrecognised node type
 * lands on: a third-party kind pushed through SchemaBuilder::node() still
 * renders, still reads and still writes its slot rather than vanishing.
 *
 * `code` is the same input in a monospace face — one control, since the only
 * difference is the face.
 */
export default {
    name: 'TextField',

    // The cell renderer binds one interface to every control, so a node this
    // one doesn't read must not land on its root element as an attribute.
    inheritAttrs: false,

    emits: ['update:modelValue'],

    props: {
        node: { type: Object, required: true },
        modelValue: { type: [String, Number], default: null },
        readOnly: { type: Boolean, default: false },
    },
};
</script>
