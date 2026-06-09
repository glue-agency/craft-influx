import { createApp } from 'vue';
import LinkBuilder from './LinkBuilder.vue';

/**
 * Boot the LinkBuilder SPA. The host Twig template renders a single mount
 * point that carries the link handle (if any) as a data attribute:
 *
 *   <div data-influx-link-builder data-handle="my-link"></div>
 *
 * For a fresh link the data-handle is omitted entirely; the SPA then
 * bootstraps from the server with a null handle and starts from defaults.
 *
 * Exported as a named function so the umbrella entry point (`main.js`) can
 * call it after also wiring up the Twig-side extras mounts, both bundles
 * shipping in a single IIFE.
 */
export function mountLinkBuilder(el) {
    const handle = el.getAttribute('data-handle') || null;
    const app = createApp(LinkBuilder, { handle });

    // Global `$t` helper bound to the `influx` translation category — any
    // component can call `this.$t('Some label')` in templates or methods.
    // Without a `Craft.translations.influx` entry (default: no translation
    // files registered), it returns the original string, so the wrap is
    // forward-compatible and breaks nothing in English-only deployments.
    // Plugins shipping translations register them server-side via
    // `$view->registerTranslations('influx', [...])` from actionEdit.
    app.config.globalProperties.$t = (str, params) => {
        if (window.Craft && typeof window.Craft.t === 'function') {
            return window.Craft.t('influx', str, params);
        }
        // Lightweight fallback: substitute `{key}` placeholders from
        // `params` so call sites with replacements still work even when
        // Craft isn't loaded (tests, isolated builds).
        if (params && typeof str === 'string') {
            return str.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m));
        }
        return str;
    };

    app.mount(el);
}

export function initLinkBuilder() {
    document
        .querySelectorAll('[data-influx-link-builder]')
        .forEach(mountLinkBuilder);
}
