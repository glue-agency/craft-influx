<template>
    <div class="influx-tab-settings">
        <div class="field">
            <div class="heading"><label class="lightswitch-label">{{ $t('Take a DB backup before every run') }}</label></div>
            <div class="instructions"><p>{{ $t('Off by default. Mainly useful for destructive processing actions.') }}</p></div>
            <div class="input">
                <light-switch v-model="link.backup" />
            </div>
        </div>
    </div>
</template>

<script>
import { store } from '../store.js';
import LightSwitch from '../LightSwitch.vue';

export default {
    name: 'SettingsTab',

    components: { LightSwitch },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },
    },
};
</script>
