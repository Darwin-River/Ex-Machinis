import './bootstrap';
// Bootstrap's jQuery plugins need window.jQuery, set in ./bootstrap above.
import 'bootstrap';
import { createApp } from 'vue';

import SpaceObjectsTable from './components/SpaceObjectsTable.vue';
import SpacecraftTable from './components/SpacecraftTable.vue';
import ProtocolsTable from './components/ProtocolsTable.vue';
import QueryCommandsTable from './components/QueryCommandsTable.vue';
import CompaniesTable from './components/CompaniesTable.vue';
import ResourcesTable from './components/ResourcesTable.vue';
import InGameEventsTable from './components/InGameEventsTable.vue';
import OrdersTable from './components/OrdersTable.vue';
import ResourceOrdersTable from './components/ResourceOrdersTable.vue';

// The whole page inside #app (rendered by Blade) is the root template.
const app = createApp({});

// Keep whitespace between elements, as Vue 2 did; inline layouts depend on it.
app.config.compilerOptions.whitespace = 'preserve';

app.component('space-objects-table', SpaceObjectsTable);
app.component('spacecraft-table', SpacecraftTable);
app.component('protocols-table', ProtocolsTable);
app.component('query-commands-table', QueryCommandsTable);
app.component('companies-table', CompaniesTable);
app.component('resources', ResourcesTable);
app.component('in-game-events-table', InGameEventsTable);
app.component('buy-orders-table', OrdersTable);
app.component('resource-orders-table', ResourceOrdersTable);

app.mixin({
    methods: {
        capitalizeFirstLetter: str => str.charAt(0).toUpperCase() + str.slice(1)
    }
});

app.mount('#app');
