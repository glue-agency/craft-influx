import { createApp } from 'vue';
import LinkBuilder from './LinkBuilder.vue';
import { installT } from '../lib/installT.js';

/**
 * Boot the LinkBuilder SPA. The host Twig template renders a single mount
 * point that carries the link id (if any) as a data attribute:
 *
 *   <div data-influx-link-builder data-id="42"></div>
 *
 * For a fresh link the data-id is omitted entirely; the SPA then
 * bootstraps from the server with a null id and starts from defaults.
 *
 * Exported as a named function so the umbrella entry point (`main.js`) can
 * call it after also wiring up the Twig-side extras mounts, both bundles
 * shipping in a single IIFE.
 */
export function mountLinkBuilder(el) {
    const raw = el.getAttribute('data-id');
    const id = raw ? Number(raw) : null;
    // Present when duplicating: the SPA bootstraps a prefilled, unsaved copy
    // of this source link instead of loading one to edit.
    const rawDuplicate = el.getAttribute('data-duplicate-of');
    const duplicateOf = rawDuplicate ? Number(rawDuplicate) : null;
    const app = createApp(LinkBuilder, { id, duplicateOf });

    installT(app);
    app.mount(el);
}

export function initLinkBuilder() {
    document
        .querySelectorAll('[data-influx-link-builder]')
        .forEach(mountLinkBuilder);
}
