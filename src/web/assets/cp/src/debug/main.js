import { createApp } from 'vue';
import DebugApp from './DebugApp.vue';
import { installT } from '../lib/installT.js';
import { installVocabulary } from '../lib/vocabulary.js';

/**
 * Boot the debug inspector. The host template renders a single mount point
 * carrying its bootstrap config as JSON:
 *
 *   <div data-influx-debug='{"inspectUrl":"…","siteHandles":[…],…}'></div>
 */
export function mountDebug(el) {
    let config = {};

    try {
        config = JSON.parse(el.getAttribute('data-influx-debug') || '{}');
    } catch (e) {
        config = {};
    }

    installVocabulary(config.vocabulary);

    const app = createApp(DebugApp, { config });
    installT(app);
    app.mount(el);
}

export function initDebug() {
    document
        .querySelectorAll('[data-influx-debug]')
        .forEach(mountDebug);
}
