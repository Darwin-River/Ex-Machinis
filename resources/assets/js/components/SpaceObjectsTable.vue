<template>
    <div>
        <form @submit.prevent="applyFilters()" id="search_form">
            <div class="row mt-4 mb-4">
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input form-control-has-validation input-md" id="object_id" v-model="objectId"
                               type="text"
                               name="object_id" data-constraints="@Numeric ">
                        <label class="form-label rd-input-label" for="object_id">Id</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="keyword" v-model="keyword"
                               type="text"
                               name="keyword">
                        <label class="form-label rd-input-label" for="keyword">Keyword</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6 mt-2 mt-md-0">
                    <div class="form-wrap form-sm">
                        <div class="select-wrapper">
                            <select class="form-input input-md" id="object_type" v-model="objectType"
                                    name="object_type">
                                <option :value="null">All types</option>
                                <option value="Star">Star</option>
                                <option value="Planet">Planet</option>
                                <option value="Moon">Moon</option>
                                <option value="Station">Station</option>
                                <option value="Comet">Comet</option>
                                <option value="Asteroid">Asteroid</option>
                                <option value="Ring">Ring</option>
                            </select>
                        </div>
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
        <img src="/images/loading.gif" id="table_preloader" v-show="loading"/>
        <div v-bind:class="'table-responsive '+(loading?' loading-table ':'') ">
            <data-table api-url="/objects/search" :fields="fields"
                      @pagination-data="onPaginationData" @loading="startLoading"
                      @loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">
                <template #object_id="props"><div>
                    <a v-bind:href="'/locations/'+props.rowData.object_id">{{props.rowData.object_id}}</a>
                </div></template>
                <template #object_name="props"><div>
                    <a v-bind:href="'/locations/'+props.rowData.object_id">{{props.rowData.object_name}}</a>
                </div></template>
                <template #central_body_name="props"><div>
                    <a v-bind:href="'/locations/'+props.rowData.central_body_id">{{props.rowData.central_body_name}}</a>
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

    export default {
        name: "SpaceObjectsTable",
        components: {
            DataTable,
            /*     VueEvents,*/
        },
        props: {
            resultsPerPage: Number,
        },
        data: () => ({
            fields: [
                {
                    name: 'object_id',
                    title: 'Id',
                    sortField: 'object_id',
                    titleClass: '',
                    dataClass: '',
                    width: "20%",

                },
                {
                    name: 'object_name',
                    title: 'Name',
                    sortField: 'object_name',
                    titleClass: '',
                    dataClass: '',
                    width: "22%",

                },
                {
                    name: 'object_type',
                    title: 'Type',
                    sortField: 'object_type',
                    titleClass: '',
                    dataClass: '',
                    width: "22%",
                    /* formatter (value) {
                         return value === 'M' ? 'Male' : 'Female'
                     }*/
                },
                {
                    name: 'central_body_name',
                    title: 'Central Body',
                    sortField: 'cb.object_name',
                    titleClass: '',
                    dataClass: '',
                    width: "36%",

                },
            ],
            extraParams: {},
            keyword: null,
            objectType: null,
            objectId: null,
            loading: false,

            totalPages: null,
            currentPage: 1,

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
                //this.selectedLetter = letter;
                let params = {};
                if (this.objectId)
                    params.object_id = this.objectId;
                if (this.keyword)
                    params.keyword = this.keyword;
                if (this.objectType)
                    params.object_type = this.objectType;

                this.extraParams = params;
                this.$nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.keyword = null;
                this.objectId = null;
                this.objectType = null;
                this.extraParams = {};
                this.$nextTick(() => this.$refs.vuetable.refresh());
            },
            startLoading() {
                this.loading = true;
            },
            stopLoading() {
                this.loading = false;
            }
        },
        computed: {}
    }
</script>

<style scoped>

</style>
