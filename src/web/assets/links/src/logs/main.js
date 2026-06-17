import { createApp } from 'vue';
import LogApp from './LogApp.vue';
import { installT } from '../lib/installT.js';

/**
 * Boot the run-log viewer. The host template renders a single mount point
 * carrying its bootstrap config as JSON:
 *
 *   <div data-influx-log='{"log":{…},"items":[…],"streamUrl":"…",…}'></div>
 */
export function mountLog(el) {
    let config = {};

    try {
        config = JSON.parse(el.getAttribute('data-influx-log') || '{}');
    } catch (e) {
        config = {};
    }

    const app = createApp(LogApp, { config });
    installT(app);
    app.mount(el);
}

export function initLog() {
    document
        .querySelectorAll('[data-influx-log]')
        .forEach(mountLog);
}
