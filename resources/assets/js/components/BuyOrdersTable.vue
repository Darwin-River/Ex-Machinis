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
            <vuetable api-url="/buys/search" :fields="fields" data-path="data" pagination-path=""
                      @vuetable:pagination-data="onPaginationData" @vuetable:loading="startLoading"
                      @vuetable:loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">
                <div slot="resource_id" slot-scope="props">
                    <a v-bind:href="'/resources/'+props.rowData.resource_id">{{props.rowData.resource_name}}</a>
                </div>
                <div slot="timestamp" slot-scope="props">
                    {{ formatDate(props.rowData.timestamp) | moment("MM/DD/YYYY h:mm:ss A") }}
                </div>
                <div slot="agent_name" slot-scope="props">
                    <a v-bind:href="'/spacecraft/'+props.rowData.agent_id">{{props.rowData.agent_name}}</a>
                </div>
                <div slot="object_id" slot-scope="props">
                    <a v-bind:href="'/objects/'+props.rowData.object_id">{{props.rowData.object_name}}</a>
                </div>
            </vuetable>
        </div>

        <div class="pagination-wrap" v-if="totalPages && totalPages>1" :key="totalPages">
            <!-- Bootstrap Pagination-->
            <nav aria-label="Page navigation">
                <ul class="pagination">
                    <li v-bind:class="'page-item '+($refs.pagination.tablePagination.current_page === index?'active':'')"
                        v-for="index in totalPages"><span v-if="$refs.pagination.tablePagination.current_page === index"
                                                          class="page-link">{{index}}</span><a v-else href=""
                                                                                               v-on:click.prevent.stop="onChangePage(index)"
                                                                                               class="page-link">{{index}}</a>
                    </li>
                </ul>
            </nav>
        </div>
        <vuetable-pagination ref="pagination" @vuetable-pagination:change-page="onChangePage"
                             :fields="fields"></vuetable-pagination>
    </div>
</template>

<script>
    import Vuetable from "vuetable-2";
    import VuetablePagination from "vuetable-2/src/components/VuetablePagination";
    import {formatDate} from "../helper";

    export default {
        name: "BuyOrdersTable",
        components: {
            Vuetable,
            VuetablePagination,
        },
        props: {
            resultsPerPage: Number,
            limitResults: Number,
            agentId: Number,
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
                    title: 'Price',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/
                },
                {
                    name: 'volume',
                    title: 'Volume',
                    /*    sortField: 'protocol_name',*/
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/
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
                this.$refs.pagination.setPaginationData(paginationData);
                this.totalPages = this.$refs.pagination.totalPage;
            },
            // when the user click something that causes the page to change,
            // call "changePage" method in Vuetable, so that that page will be
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
                Vue.nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.keyboard = null;
                Vue.nextTick(() => this.$refs.vuetable.refresh());
            },
            startLoading() {
                this.loading = true;
            },
            stopLoading() {
                this.loading = false;
            },
            formatDate(date) {
                return formatDate(date);
            },
        },
    }
</script>

<style scoped>

</style>