<template>
    <div class="influx-note">
        <p v-if="node.text" class="light">
            {{ node.text }}
            <!-- The text itself is escaped, so a note that needs to point somewhere
                 carries the target as its own key rather than markup. -->
            <a v-if="node.url" :href="node.url" target="_blank" rel="noopener noreferrer"
               v-text="node.linkText || node.url"></a>
        </p>
        <!-- A worked example, where the shortest true answer is a feed snippet
             rather than a sentence. Escaped like the text, and preformatted:
             the whitespace is the explanation. -->
        <pre v-if="node.example" class="influx-note-example"><code v-text="node.example"></code></pre>
    </div>
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
 * Binds nothing — it has no slot, reads no value and emits no write. Which is
 * also what makes it the right carrier for a showIf-gated explanation: several
 * notes can describe the same setting's alternatives, and only the one matching
 * the current value renders.
 */
export default {
    name: 'NoteField',

    inheritAttrs: false,

    props: {
        node: { type: Object, required: true },
    },
};
</script>
