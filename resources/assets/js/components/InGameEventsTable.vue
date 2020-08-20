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

            <vuetable api-url="/events/search" :fields="fields" data-path="data" pagination-path=""
                      @vuetable:pagination-data="onPaginationData" @vuetable:loading="startLoading"
                      @vuetable:loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">

                <div slot="acting_agent_id" slot-scope="props">
                    <div v-if="props.rowData.acting_agent_id">
                        <a v-bind:href="'/spacecraft/'+props.rowData.acting_agent_id">{{props.rowData.acting_agent_name}}</a>
                        <span v-if="!agentId">(<a v-bind:href="'/companies/'+props.rowData.acting_company_id">{{props.rowData.acting_company_name}}</a>)</span>
                    </div>
                    <div v-else>
                        -
                    </div>
                </div>
                <div slot="affected_agent_id" slot-scope="props">
                    <div v-if="props.rowData.affected_agent_id">
                        <a v-bind:href="'/spacecraft/'+props.rowData.affected_agent_id">{{props.rowData.affected_agent_name}}</a>
                        <span v-if="!agentId">(<a v-bind:href="'/companies/'+props.rowData.affected_company_id">{{props.rowData.affected_company_name}}</a>)</span>
                    </div>
                    <div v-else>
                        -
                    </div>
                </div>
                <div slot="resource_name" slot-scope="props">
                    <a v-bind:href="'/resources/'+props.rowData.resource_id">{{props.rowData.resource_name}}</a> <span
                        class="icon mdi mdi-lock" title="resource in use" v-if="props.rowData.locked === 1"></span>
                </div>
                <div slot="object_name" slot-scope="props">
                    <a v-bind:href="'/objects/'+props.rowData.object_id">{{props.rowData.object_name}}</a>
                </div>
                <div slot="affected_company_name" slot-scope="props">
                    <a v-bind:href="'/company/'+props.rowData.affected_company_id">{{props.rowData.affected_company_name}}</a>
                </div>
                <div slot="affected_resource_name" slot-scope="props">
                    <a v-bind:href="'/resources/'+props.rowData.affected_resource_id">{{props.rowData.affected_resource_name}}</a>
                </div>
                <div slot="timestamp" slot-scope="props">
                    {{ formatDate(props.rowData.timestamp) | moment("MM/DD/YYYY h:mm:ss A") }}
                </div>
                <div slot="event_type_name" slot-scope="props">
                    {{props.rowData.event_type_name}} <span v-if="props.rowData.new_quantity">{{props.rowData.new_quantity}} units</span><span
                        v-if="props.rowData.new_credits"> {{props.rowData.new_credits}} credits</span><span
                        v-if="props.rowData.object_name"><a v-bind:href="/objects/+props.rowData.object_id">{{props.rowData.object_name}}</a></span>
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
    import {formatDate} from '../helper.js';

    export default {
        name: "InGameEventsTable",
        components: {
            Vuetable,
            VuetablePagination,
            /*     VueEvents,*/
        },
        props: {
            resultsPerPage: Number,
            limitResults: Number,
            agentId: Number,
        },
        data: () => ({
            fields: [
                {
                    name: 'timestamp',
                    title: 'Time',
                    sortField: 'timestamp',
                    titleClass: '',
                    dataClass: '',
                    /*   width: "10%",*/
                },
                {
                    name: 'acting_agent_id',
                    title: 'Acting Spacecraft',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/
                },
                {
                    name: 'protocol_name',
                    title: 'Action',
                    /*    sortField: 'protocol_name',*/
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/
                },
                {
                    name: 'affected_agent_id',
                    title: 'Affected Spacecraft',
                    /* sortField: 'affected_company_name',*/
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },
                /*   {
                       name: 'affected_agent_name',
                       title: 'Affected Spacecraft',

                       titleClass: '',
                       dataClass: '',
                   },*/
                {
                    name: 'resource_name',
                    title: 'Affected Resource',
                    /* sortField: 'resource_name',*/
                    titleClass: '',
                    dataClass: '',
                },
                {
                    name: 'event_type_name',
                    title: 'Effect',
                    /*     sortField: 'event_type_name',*/
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/
                },

                /* {
                     name: 'locked',
                     title: '',
                     titleClass: '',
                     dataClass: '',
                     formatter(value) {
                         return value === 1 ? '<span class="icon mdi mdi-lock" title="resource in use"></span> ' : ''
                     }
                 },*/

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
        created() {
            if (this.agentId != null) {
                this.extraParams.observing_agent = this.agentId;
                this.fields[0].sortField = null;
            }
            if (this.limitResults)
                this.extraParams.limit = this.limitResults;
        },
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
                let params = this.extraParams;
                if (this.keyword)
                    this.extraParams.keyword = this.keyword;
                else
                    delete this.extraParams.keyword;

                //this.extraParams = params;
                Vue.nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.keyword = null;
                // this.extraParams = {};
                delete this.extraParams.keyword;
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
            }
        },
    }
</script>

<style scoped>
    @media (max-width: 1200px) {
        .table-responsive {
            display: block;
            width: 100%;
            overflow-x: auto;
            -ms-overflow-style: -ms-autohiding-scrollbar;
        }

        .table-responsive.table-bordered {
            border: 0;
        }
    }
</style>