let mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 */
mix.setPublicPath('html/');
mix.js('resources/assets/js/app.js', 'html/js')

    .sass('resources/assets/sass/app.scss', 'html/css') /*.autoload({
    jQuery: 'jquery',
    $: 'jquery',
    jquery: 'jquery'

}) */  /*.js('resources/assets/js/core.min.js', 'html/js')
    .js('resources/assets/js/script.js', 'html/js')*/;
if (mix.inProduction()) {
    mix.version();
}

mix.browserSync({proxy:'localhost'});