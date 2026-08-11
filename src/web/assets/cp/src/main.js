import './styles/index.css';

import { initLinkBuilder } from './builder/main.js';
import { initDebug } from './debug/main.js';
import { initLog, initLogFilters } from './logs/main.js';

/**
 * Single entry point for the Influx CP bundle. Each Influx CP screen renders
 * its own mount root and the matching app boots wherever its root is found:
 *
 *   [data-influx-link-builder]  → the link editor SPA
 *   [data-influx-debug]         → the debug inspector
 *   [data-influx-log]           → the run-log viewer
 *   [data-influx-log-filters]   → the Logs overview's filter toolbar
 *
 * One IIFE bundle, four independent mounts — a screen only pays for the app
 * whose root it renders.
 */
function init() {
    initLinkBuilder();
    initDebug();
    initLog();
    initLogFilters();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}
