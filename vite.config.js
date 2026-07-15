import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Builds the CP UI bundle for the Influx plugin. Output lands inside the
 * Links asset bundle's `dist/` directory so Craft's asset publisher just
 * serves the compiled files like any other plugin resource.
 */
export default defineConfig({
    plugins: [vue()],

    root: resolve(here, 'src/web/assets/links/src'),

    build: {
        outDir: resolve(here, 'src/web/assets/links/dist'),
        emptyOutDir: true,
        manifest: false,
        sourcemap: true,
        cssCodeSplit: false,
        rollupOptions: {
            input: resolve(here, 'src/web/assets/links/src/main.js'),
            // IIFE so top-level `let`/`const` in Vue (e.g. activeEffectScope,
            // which the minifier renames to `$`) stay function-scoped instead
            // of going into the script-scope's lexical env and shadowing
            // window.$ for every script that follows. Otherwise Craft's CP
            // Tabs.js (and the rest of the CP JS) breaks with "$ is not a
            // function".
            output: {
                format: 'iife',
                name: 'InfluxLinks',
                inlineDynamicImports: true,
                entryFileNames: 'js/influx-links.js',
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
