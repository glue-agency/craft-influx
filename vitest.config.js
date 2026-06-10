import { defineConfig } from 'vitest/config';

/**
 * Kept separate from vite.config.js on purpose — that file configures the
 * IIFE CP-asset build (fixed entry, no module preload), all of which is
 * irrelevant or hostile to the test runner.
 */
export default defineConfig({
    test: {
        environment: 'happy-dom',
        include: ['src/web/assets/links/src/**/__tests__/*.test.js'],
        setupFiles: ['src/web/assets/links/tests/setup.js'],
    },
});
