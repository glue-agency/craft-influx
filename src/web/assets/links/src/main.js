import { createApp } from 'vue';
import MappingExtras from './components/MappingExtras.vue';

/**
 * Mount the typed-mapping extras components. Each row in the mapping list
 * that exposes a `fieldMeta.kind` gets one mount point in Twig:
 *
 *   <div data-influx-extras-app
 *        data-bootstrap='{"field": {...}, "saved": {...}, "namespace": "..."}'>
 *   </div>
 *
 * Vue takes over and renders the kind-specific form. State is committed back
 * to the DOM as hidden inputs so the regular form submission picks it up.
 */
function mountExtras(el) {
    let data = {};

    try {
        data = JSON.parse(el.getAttribute('data-bootstrap') || '{}');
    } catch (e) {
        console.error('[influx] Failed to parse MappingExtras bootstrap data', e);
        return;
    }

    const app = createApp(MappingExtras, {
        field: data.field || {},
        saved: data.saved || {},
        namespace: data.namespace || '',
        readOnly: !!data.readOnly,
    });
    app.mount(el);
}

function init() {
    document
        .querySelectorAll('[data-influx-extras-app]')
        .forEach(mountExtras);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
