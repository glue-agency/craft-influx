<template>
    <ul v-if="messages?.length" class="errors">
        <li v-for="(msg, i) in messages" :key="i">{{ msg }}</li>
    </ul>
</template>

<script>
/**
 * Tiny one-shot renderer for Craft's `<ul class="errors">` error block.
 * Drop after any input that maps to a server-side validation attribute
 * and pass `state.errors[attr]` — Craft's CP CSS handles the red styling.
 */
export default {
    name: 'FieldErrors',

    props: {
        messages: { type: Array, default: () => [] },
    },
};
</script>
