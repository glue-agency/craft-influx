import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Shared with vitest.config.js, which resolves the same imports from a different
 * root — keep the two reading one definition.
 */
export const aliases = {
    '@cp': resolve(here, 'src/web/assets/cp/src'),
    '@integrations': resolve(here, 'src/integrations'),
};

/**
 * Builds the CP UI bundle for the Influx plugin. Output lands inside the
 * CP asset bundle's `dist/` directory so Craft's asset publisher just
 * serves the compiled files like any other plugin resource.
 */
export default defineConfig({
    plugins: [vue({
        // `<craft-tooltip>` is a custom element Craft's CP registers, so Vue
        // must render it as-is instead of trying to resolve a component.
        template: { compilerOptions: { isCustomElement: (tag) => tag.startsWith('craft-') } },
    })],

    root: resolve(here, 'src/web/assets/cp/src'),

    // An integration's Vue lives with the PHP it belongs to
    // (src/integrations/<vendor>/<plugin>/resources), which is outside the root
    // above — so both directions need a name rather than a walk of `../`s.
    resolve: { alias: aliases },

    build: {
        outDir: resolve(here, 'src/web/assets/cp/dist'),
        emptyOutDir: true,
        manifest: false,
        sourcemap: true,
        cssCodeSplit: false,
        rollupOptions: {
            input: resolve(here, 'src/web/assets/cp/src/main.js'),
            // IIFE so top-level `let`/`const` in Vue (e.g. activeEffectScope,
            // which the minifier renames to `$`) stay function-scoped instead
            // of going into the script-scope's lexical env and shadowing
            // window.$ for every script that follows. Otherwise Craft's CP
            // Tabs.js (and the rest of the CP JS) breaks with "$ is not a
            // function".
            output: {
                format: 'iife',
                name: 'InfluxApp',
                inlineDynamicImports: true,
                entryFileNames: 'js/influx-app.js',
                chunkFileNames: 'js/[name]-[hash].js',
                assetFileNames: (assetInfo) => {
                    if (assetInfo.name && assetInfo.name.endsWith('.css')) {
                        return 'css/influx-app.css';
                    }
                    return 'assets/[name][extname]';
                },
            },
        },
    },
});
