import _ from 'lodash';
import axios from 'axios';
import jQuery from 'jquery';
import Popper from 'popper.js';

window._ = _;

// html/js/core.min.js ships its own jQuery and the theme scripts use it as
// the global $. This bundle loads as a module, after those scripts, so keep
// theirs if it's already there (as with the old script order).
window.$ = window.jQuery = window.jQuery || jQuery;
window.Popper = window.Popper || Popper;

/**
 * axios sends the CSRF token with every request, from the meta tag in the layout.
 */
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
} else {
    console.error('CSRF token not found: https://laravel.com/docs/csrf#csrf-x-csrf-token');
}
