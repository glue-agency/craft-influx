<template>
    <div class="influx-tab-auth">
        <div class="field">
            <div class="heading"><label for="builder-auth-type">{{ $t('Authentication type') }}</label></div>
            <div class="instructions">
                <p>{{ $t('How Influx should authenticate against the remote API.') }}</p>
            </div>
            <div class="input ltr">
                <div class="select">
                    <select id="builder-auth-type" v-model="type">
                        <option v-for="opt in options.authTypes" :key="opt.value || '_'" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <schema-form
            v-if="activeStrategy"
            layout="stacked"
            :schema="activeStrategy.schema"
            :options="link.auth || {}"
            :token-groups="envSuggestions"
            @update:options="writeAuth"
        />

        <p v-else-if="type" class="light">
            {{ $t('No SPA-side schema is registered for auth type') }} <code>{{ type }}</code>.
        </p>

        <!-- Server-side `validateAuth` errors apply to the whole block
             rather than any single field, so we surface them once below. -->
        <field-errors :messages="ui.errors.auth" />
    </div>
</template>

<script>
import { store } from '../store.js';
import { pruneEmpty } from '../lib/mappings.js';
import FieldErrors from '../FieldErrors.vue';
import SchemaForm from '../schema/SchemaForm.vue';

/**
 * Authentication tab. The auth-type select drives which per-strategy schema
 * renders below — the same generic SchemaForm the mapping extras use, in
 * its stacked (Craft .field block) layout. Schemas come through the
 * bootstrap options (see `LinkBuilderService::authStrategyDefinitions()`,
 * which translates each strategy's editSchema() into BuilderSchema nodes);
 * third-party strategies without one fall back to the message below.
 *
 * Writes flow into `link.auth = { type, ...fields }`. Swapping the type
 * resets the field slot — the old strategy's saved values are dropped on
 * change, mirroring how the legacy Twig form re-submitted only the active
 * partial's inputs.
 */
export default {
    name: 'AuthTab',

    components: { FieldErrors, SchemaForm },

    data() {
        return {
            options: store.ui.options,
            ui: store.ui,
        };
    },

    computed: {
        // Through the stable getter — load()/save() replace the underlying
        // object, so a data() capture would go stale.
        link() { return store.link; },

        type: {
            get() { return this.link.auth?.type ?? ''; },
            set(v) {
                if (!v) {
                    this.link.auth = {};
                    return;
                }
                // Keep only the type when switching — the previous
                // strategy's token/header/param fields don't apply to the
                // new one and would otherwise leak into Project Config.
                this.link.auth = { type: v };
            },
        },

        activeStrategy() {
            const defs = this.options.authStrategies || [];
            return defs.find(d => d.type === this.type) || null;
        },

        // Env vars + Craft aliases for tokenInput schema nodes — the
        // same picker items the endpoint inputs use.
        envSuggestions() {
            return this.ui.meta?.envSuggestions || [];
        },
    },

    methods: {
        writeAuth(next) {
            // Drop empty values so saved Project Config stays clean; the
            // type always rides along.
            this.link.auth = { ...pruneEmpty(next), type: this.type };
        },
    },
};
</script>
