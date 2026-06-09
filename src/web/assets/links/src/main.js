import { initLinkBuilder } from './builder/main.js';

/**
 * Single entry point for the Influx CP bundle. Boots the LinkBuilder SPA
 * wherever its mount root is found on the page. The Vue-only architecture
 * means there's no longer any per-mapping-row mount loop — MappingExtras
 * lives entirely inside the SPA's MappingRow component.
 */
function init() {
    initLinkBuilder();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
