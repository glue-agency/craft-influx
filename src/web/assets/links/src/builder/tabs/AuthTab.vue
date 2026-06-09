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

        <template v-if="activeStrategy">
            <div class="field" v-for="field in activeStrategy.fields" :key="field.handle">
                <div class="heading"><label :for="`builder-auth-${field.handle}`">{{ field.label }}</label></div>
                <div v-if="field.instructions" class="instructions"><p v-html="field.instructions" /></div>
                <div class="input ltr">
                    <input
                        :id="`builder-auth-${field.handle}`"
                        type="text"
                        :class="['text', 'fullwidth', field.inputType === 'code' ? 'code' : null]"
                        :value="link.auth?.[field.handle] ?? ''"
                        @input="writeField(field.handle, $event.target.value)"
                    />
                </div>
            </div>
        </template>

        <p v-else-if="type && !activeStrategy" class="light">
            {{ $t('No SPA-side schema is registered for auth type') }} <code>{{ type }}</code>.
        </p>

        <!-- Server-side `validateAuth` errors apply to the whole block
             rather than any single field, so we surface them once below. -->
        <field-errors :messages="state.errors.auth" />
    </div>
</template>

<script>
import { store } from '../store.js';
import FieldErrors from '../FieldErrors.vue';

/**
 * Authentication tab. The auth-type select drives which per-strategy field
 * set renders below. Schemas come through the bootstrap meta (see
 * `LinkBuilderService::authStrategyDefinitions()`); third-party strategies
 * without a schema fall back to the "no SPA-side schema" message.
 *
 * Writes flow into `link.auth = { type, ...fields }`. Swapping the type
 * resets the field slot — the old strategy's saved values are dropped on
 * change, mirroring how the legacy Twig form re-submitted only the active
 * partial's inputs.
 */
export default {
    name: 'AuthTab',

    components: { FieldErrors },

    data() {
        return {
            link: store.raw.link,
            options: store.state.options,
            state: store.state,
        };
    },

    computed: {
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
    },

    methods: {
        writeField(handle, value) {
            // Drop empty values so saved Project Config stays clean —
            // matches LinkPostNormalizer::auth() behavior.
            const next = { ...(this.link.auth || {}), type: this.type };
            if (value === '' || value == null) {
                delete next[handle];
            } else {
                next[handle] = value;
            }
            this.link.auth = next;
        },
    },
};
</script>
