<template>
    <div class="influx-error-panel"><pre>{{ error }}</pre></div>
</template>

<script>
/**
 * A block of preformatted error text — the full-width error band in the log
 * viewer and the feed-level error in the debug inspector. Owns the shared
 * `<pre>` treatment (no margin, small type, wrap long lines); the band chrome
 * that differs per app — background, borders, padding, text colour — stays on
 * the consumer's fallthrough class (influx-log-error, influx-feed-error).
 */
export default {
    name: 'ErrorPanel',

    props: {
        error: { type: String, default: '' },
    },
};
</script>

<style scoped>
/* Text colour is inherited on purpose: the apps disagree (#8a1f1f vs
   var(--error-color)), so each sets it on its fallthrough class. */
.influx-error-panel pre {
    margin: 0;
    font-size: 12px;
    white-space: pre-wrap;
    word-break: break-word;
}
</style>
