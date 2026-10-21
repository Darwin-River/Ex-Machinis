import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue2';

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
                transformAssetUrlsOptions: {
                    // Leave absolute URLs like /images/loading.gif alone;
                    // they're served directly from html/.
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
    resolve: {
        alias: {
            // Full build with the template compiler; components are also used from Blade.
            vue: 'vue/dist/vue.esm.js',
        },
    },
});
