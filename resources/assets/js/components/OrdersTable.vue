<template>
    <div>
        <form @submit.prevent="applyFilters()" id="search_form">
            <div class="row mt-4 mb-4">
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="keyword" v-model="keyword"
                               type="text"
                               name="agent_name">
                        <label class="form-label rd-input-label" for="keyword">Keyword</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6 mt-0 mt-md-0">
                    <button class="button button-sm button-primary " type="submit"
                            id="update_button">Update
                    </button>
                    <button class="button button-sm button-secondary mt-0 ml-lg-1 ml-xl-2 d-none d-lg-inline-block"
                            type="submit" @click="resetFilters()">Reset
                    </button>
                </div>
            </div>
        </form>

        <img src="/images/loading.gif" id="table_preloader" v-show="loading"/>
        <div v-bind:class="'table-responsive '+(loading?' loading-table ':'') ">
            <data-table :api-url="dataUrl" :fields="fields"
                      @pagination-data="onPaginationData" @loading="startLoading"
                      @loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">
                <template #resource_id="props"><div>
                    <a v-bind:href="'/resources/'+props.rowData.resource_id">{{props.rowData.resource_name}}</a>
                </div></template>
                <template #timestamp="props"><div>
                    {{ formatDateTime(props.rowData.timestamp) }}
                </div></template>
                <template #agent_name="props"><div>
                    <a v-bind:href="'/spacecraft/'+props.rowData.agent_id">{{props.rowData.agent_name}}</a>
                </div></template>
                <template #object_id="props"><div>
                    <a v-bind:href="'/locations/'+props.rowData.object_id">{{props.rowData.object_name}}</a>
                </div></template>
            </data-table>
        </div>

        <div class="pagination-wrap" v-if="totalPages && totalPages>1" :key="totalPages">
            <!-- Bootstrap Pagination-->
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <li v-bind:class="'page-item '+(currentPage === index?'active':'')"
                        v-for="index in totalPages"><span v-if="currentPage === index"
                                                          class="page-link">{{index}}</span><a v-else href=""
                                                                                               v-on:click.prevent.stop="onChangePage(index)"
                                                                                               class="page-link">{{index}}</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<script>
    import DataTable from './DataTable.vue';
    import {formatDateTime} from '../helper.js';

    export default {
        name: "OrdersTable",
        components: {
            DataTable,
        },
        props: {
            resultsPerPage: Number,
            limitResults: Number,
            agentId: Number,
            dataUrl: String,
        },
        data: () => ({
            fields: [
                {
                    name: 'resource_id',
                    title: 'Resource',
                    sortField: 'resources.id',
                    titleClass: '',
                    dataClass: '',
                },
                {
                    name: 'price',
                    title: 'Unit Price',
                    titleClass: '',
                    dataClass: '',
                    formatter(value) {
                        return value + '<span class="d-none d-md-inline"> credits</span>';
                    }
                },
                {
                    name: 'volume',
                    title: 'Volume',
                    /*    sortField: 'protocol_name',*/
                    titleClass: '',
                    dataClass: '',
                    formatter(value) {
                        return value + '<span class="d-none d-md-inline"> units</span>';
                    }
                },
                {
                    name: 'timestamp',
                    title: 'Posted On',
                    sortField: 'events.timestamp',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },
                {
                    name: 'agent_name',
                    title: 'Posted By',
                    sortField: 'agents.name',
                    titleClass: '',
                    dataClass: '',
                },
                {
                    name: 'object_id',
                    title: 'Location',
                    sortField: 'objects.object_name',
                    titleClass: '',
                    dataClass: '',
                },


            ],
            extraParams: {},
            keyword: null,
            loading: false,

            totalPages: null,
            currentPage: 1,

            css: {
                table: {
                    tableClass: 'small-version table-custom table-custom-striped table-custom-primary ui blue selectable unstackable celled table',
                    ascendingIcon: 'icon mdi mdi-chevron-up',
                    descendingIcon: 'icon mdi mdi-chevron-down',
                    sortableIcon: 'icon mdi mdi-swap-vertical',
                    handleIcon: 'icon mdi mdi-hand-left',
                    loadingClass: 'loading',
                },
                pagination: {}
            },

        }),
        methods: {
            //...
            // when the pagination data is available, set it to pagination component
            onPaginationData(paginationData) {
                this.currentPage = paginationData.current_page;
                this.totalPages = paginationData.last_page;
            },
            // when the user click something that causes the page to change,
            // call "changePage" on the data table, so that that page will be
            // requested from the API endpoint.
            onChangePage(page) {
                this.$refs.vuetable.changePage(page);
                document.getElementById('search_form').scrollIntoView();
            },

            applyFilters() {

                let params = {};
                if (this.keyword)
                    params.keyword = this.keyword;

                this.extraParams = params;
                this.$nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.keyboard = null;
                this.$nextTick(() => this.$refs.vuetable.refresh());
            },
            startLoading() {
                this.loading = true;
            },
            stopLoading() {
                this.loading = false;
            },
            formatDateTime(date) {
                return formatDateTime(date);
            },
        },
    }
</script>

<style scoped>

</style>
