<template>
    <div>
        <form @submit.prevent="applyFilters()" id="search_form">
            <div class="row mt-4 mb-4">
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="location" v-model="location"
                               type="text"
                               name="agent_name">
                        <label class="form-label rd-input-label" for="location">Location</label>
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
            <vuetable :api-url="dataUrl" :fields="fields" data-path="data" pagination-path=""
                      @vuetable:loading="startLoading"
                      @vuetable:loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">

                <div slot="timestamp" slot-scope="props">
                    {{ formatDate(props.rowData.timestamp) | moment("MM/DD/YYYY h:mm:ss A") }}
                </div>
                <div slot="agent_name" slot-scope="props">
                    <a v-bind:href="'/spacecraft/'+props.rowData.agent_id">{{props.rowData.agent_name}}</a>
                </div>
                <div slot="object_id" slot-scope="props">
                    <a v-bind:href="'/locations/'+props.rowData.object_id">{{props.rowData.object_name}}</a>
                </div>

            </vuetable>
        </div>

    </div>
</template>

<script>
    import Vuetable from "vuetable-2";
    import VuetablePagination from "vuetable-2/src/components/VuetablePagination";
    import {formatDate} from "../helper";

    export default {
        name: "ResourceOrdersTable",
        components: {
            Vuetable,
            VuetablePagination,
        },
        props: {
            resourceId: Number,
            dataUrl: String,
            /*   operation: Number,*/
        },
        data: () => ({
            fields: [
                /* {
                     name: 'resource',
                     title: 'Resource',
                     sortField: 'resource',
                     titleClass: '',
                     dataClass: '',
                 },*/
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
                    sortField: 'volume',
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
                    /*  sortField: 'agents.name',*/
                    titleClass: '',
                    dataClass: '',
                },
                {
                    name: 'object_id',
                    title: 'Location',
                    /*  sortField: 'objects.object_name',*/
                    titleClass: '',
                    dataClass: '',
                },


            ],
            extraParams: {},
            location: null,
            loading: false,

            totalPages: null,
            resultsPerPage: 10,

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
        /*    onPaginationData(paginationData) {
                this.$refs.pagination.setPaginationData(paginationData);
                this.totalPages = this.$refs.pagination.totalPage;
            },*/
            // when the user click something that causes the page to change,
            // call "changePage" method in Vuetable, so that that page will be
            // requested from the API endpoint.
         /*   onChangePage(page) {
                this.$refs.vuetable.changePage(page);
                document.getElementById('search_form').scrollIntoView();
            },*/

            applyFilters() {

                let params = {};
                if (this.location)
                    params.location = this.location;

                this.extraParams = params;
                Vue.nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.location = null;
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
