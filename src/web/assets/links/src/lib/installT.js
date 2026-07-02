/**
 * Translation helper bound to the `influx` category. Without a
 * `Craft.translations.influx` entry it returns the source string, so the wrap
 * is forward-compatible. Falls back to `{key}` placeholder substitution when
 * Craft isn't loaded (tests / isolated builds). Plugins shipping translations
 * register them server-side via `$view->registerTranslations('influx', [...])`.
 */
export function t(str, params) {
    if (window.Craft && typeof window.Craft.t === 'function') {
        return window.Craft.t('influx', str, params);
    }

    if (params && typeof str === 'string') {
        return str.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m));
    }

    return str;
}

/**
 * Install the global `$t` helper so any component in any of the Influx Vue
 * apps (builder, debug, logs) can call `this.$t('Some label')`.
 */
export function installT(app) {
    app.config.globalProperties.$t = t;

    return app;
}
