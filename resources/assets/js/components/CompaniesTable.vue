<template>
    <div>
        <form @submit.prevent="applyFilters()" id="search_form">
            <div class="row mt-4 mb-4">
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input form-control-has-validation input-md" id="company_id" v-model="companyId"
                               type="text"
                               name="object_id" data-constraints="@Numeric ">
                        <label class="form-label rd-input-label" for="company_id">Id</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="company_name" v-model="companyName"
                               type="text"
                               name="agent_name">
                        <label class="form-label rd-input-label" for="company_name">Name</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6 mt-2 mt-md-0">
                    <button class="button button-sm button-primary " type="submit"
                            id="update_button">Update
                    </button>
                    <button class="button button-sm button-secondary mt-0 ml-lg-1 ml-xl-2 d-none d-lg-inline-block"
                            type="submit" @click="resetFilters()">Reset
                    </button>
                </div>
            </div>
        </form>

        <div v-bind:class="'table-responsive '+(loading?' loading-table ':'') ">
            <vuetable api-url="/company/search" :fields="fields" data-path="data" pagination-path=""
                      @vuetable:pagination-data="onPaginationData" @vuetable:loading="startLoading"
                      @vuetable:loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">
                <div slot="user_id" slot-scope="props">
                    <a v-bind:href="'/company/'+props.rowData.user_id">{{props.rowData.user_id}}</a>
                </div>
                <div slot="name" slot-scope="props">
                    <a v-bind:href="'/company/'+props.rowData.user_id">{{props.rowData.name}}</a>
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

    export default {
        name: "CompaniesTable",
        components: {
            Vuetable,
            VuetablePagination,
            /*     VueEvents,*/
        },
        props: {
            resultsPerPage: Number,
        },
        data: () => ({
            fields: [
                {
                    name: 'user_id',
                    title: 'Id',
                    sortField: 'user_id',
                    titleClass: '',
                    dataClass: '',
                    /*   width: "10%",*/

                },
                {
                    name: 'name',
                    title: 'Name',
                    sortField: 'name',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },
                {
                    name: 'age',
                    title: 'Age (days)',
                    sortField: 'age',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },
                {
                    name: 'agents',
                    title: 'Spacecraft',
                    sortField: 'agents',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },
                {
                    name: 'credits',
                    title: 'Credits',
                    sortField: 'credits',
                    titleClass: '',
                    dataClass: '',
                    /*width: "22%",*/

                },

            ],
            extraParams: {},
            companyName: null,
            companyId: null,
            loading: false,

            totalPages: null,

            css: {
                table: {
                    tableClass: 'table-custom table-custom-striped table-custom-primary ui blue selectable unstackable celled',
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
                if (this.companyId)
                    params.user_id = this.companyId;
                if (this.companyName)
                    params.name = this.companyName;

                this.extraParams = params;
                Vue.nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.companyName = null;
                this.companyId = null;
                this.extraParams = {};
                Vue.nextTick(() => this.$refs.vuetable.refresh());
            },
            startLoading() {
                this.loading = true;
            },
            stopLoading() {
                this.loading = false;
            }
        },
    }
</script>

<style scoped>

</style>