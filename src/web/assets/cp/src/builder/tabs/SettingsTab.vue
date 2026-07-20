<template>
    <div class="influx-tab-settings">
        <div class="field">
            <div class="heading"><label class="lightswitch-label" v-text="$t('Take a DB backup before every run')"></label></div>
            <div class="instructions"><p v-text="$t('Off by default. Mainly useful for destructive processing actions.')"></p></div>
            <div class="input">
                <v-light-switch v-model="link.backup" :disabled="readOnly" />
            </div>
        </div>
    </div>
</template>

<script>
import { store } from '../store.js';
import LightSwitch from '../LightSwitch.vue';

export default {
    name: 'SettingsTab',

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        readOnly() { return !!store.ui.meta?.readOnly; },
    },

    components: { 'v-light-switch': LightSwitch },
};
</script>
