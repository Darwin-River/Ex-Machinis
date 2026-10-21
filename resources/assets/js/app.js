import './bootstrap';
// Bootstrap's jQuery plugins need window.jQuery, set in ./bootstrap above.
import 'bootstrap';
import Vue from 'vue';
import VueMoment from 'vue-moment';

import SpaceObjectsTable from './components/SpaceObjectsTable.vue';
import SpacecraftTable from './components/SpacecraftTable.vue';
import ProtocolsTable from './components/ProtocolsTable.vue';
import QueryCommandsTable from './components/QueryCommandsTable.vue';
import CompaniesTable from './components/CompaniesTable.vue';
import ResourcesTable from './components/ResourcesTable.vue';
import InGameEventsTable from './components/InGameEventsTable.vue';
import OrdersTable from './components/OrdersTable.vue';
import ResourceOrdersTable from './components/ResourceOrdersTable.vue';

window.Vue = Vue;

Vue.component('space-objects-table', SpaceObjectsTable);
Vue.component('spacecraft-table', SpacecraftTable);
Vue.component('protocols-table', ProtocolsTable);
Vue.component('query-commands-table', QueryCommandsTable);
Vue.component('companies-table', CompaniesTable);
Vue.component('resources', ResourcesTable);
Vue.component('in-game-events-table', InGameEventsTable);
Vue.component('buy-orders-table', OrdersTable);
Vue.component('resource-orders-table', ResourceOrdersTable);

Vue.use(VueMoment);

Vue.mixin({
    methods: {
        capitalizeFirstLetter: str => str.charAt(0).toUpperCase() + str.slice(1)
    }
});

new Vue({
    el: '#app',
});
