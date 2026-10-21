import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/assets/sass/app.scss', 'resources/assets/js/app.js'],
            // The web root is html/, not public/.
            publicDirectory: 'html',
            refresh: true,
        }),
        vue({
            template: {
                // Keep whitespace between elements, as the old vue-loader build
                // did; the layout of inline buttons depends on it.
                compilerOptions: { whitespace: 'preserve' },
                transformAssetUrls: {
                    // Leave absolute URLs like /images/loading.gif alone;
                    // they're served directly from html/.
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Bootstrap 4 (npm and the theme's copy) uses Sass features that
                // newer Sass deprecates; silence those warnings, they aren't errors.
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'slash-div', 'if-function', 'abs-percent'],
            },
        },
    },
    define: {
        __VUE_OPTIONS_API__: true,
        __VUE_PROD_DEVTOOLS__: false,
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
    },
    resolve: {
        alias: {
            // Build with the runtime template compiler: the page inside #app is
            // rendered by Blade and compiled by Vue in the browser.
            vue: 'vue/dist/vue.esm-bundler.js',
        },
    },
});
