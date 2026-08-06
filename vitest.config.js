import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

/**
 * Kept separate from vite.config.js on purpose — that file configures the
 * IIFE CP-asset build (fixed entry, no module preload), all of which is
 * irrelevant or hostile to the test runner.
 */
export default defineConfig({
    plugins: [vue({
        // `<craft-tooltip>` is a custom element Craft's CP registers, so Vue
        // must render it as-is instead of trying to resolve a component.
        template: { compilerOptions: { isCustomElement: (tag) => tag.startsWith('craft-') } },
    })],
    test: {
        environment: 'happy-dom',
        include: [
            'src/web/assets/cp/src/**/__tests__/*.test.js',
            // The editor asset is a plain IIFE rather than part of the CP bundle,
            // but its DOM probing is subtle enough to want specs of its own.
            'src/web/assets/editor/**/__tests__/*.test.js',
        ],
        setupFiles: ['src/web/assets/cp/tests/setup.js'],
    },
});
