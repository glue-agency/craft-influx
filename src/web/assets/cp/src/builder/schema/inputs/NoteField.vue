<template>
    <p class="light" v-text="node.text"></p>
</template>

<script>
/**
 * Static explanatory text — the control that says why there is no control.
 *
 * A note renders wherever its region does, which is the whole reason there is no
 * inline-vs-collapsible rule to keep: a Matrix's "no block types yet" note sits in
 * the extras it belongs to, and a Preparse field's "can't be mapped" note takes
 * the source cell the node select would have occupied.
 *
 * Binds nothing — it has no slot, reads no value and emits no write.
 */
export default {
    name: 'NoteField',

    inheritAttrs: false,

    props: {
        node: { type: Object, required: true },
    },
};
</script>
