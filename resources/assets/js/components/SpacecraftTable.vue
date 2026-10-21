<template>
    <div>
        <form @submit.prevent="applyFilters()" id="search_form">
            <div class="row mt-4 mb-4">
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input form-control-has-validation input-md" id="agent_id" v-model="agentId"
                               type="text"
                               name="object_id" data-constraints="@Numeric ">
                        <label class="form-label rd-input-label" for="agent_id">Id</label>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="agent_name" v-model="agentName"
                               type="text"
                               name="agent_name">
                        <label class="form-label rd-input-label" for="agent_name">Name</label>
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
            <data-table api-url="/spacecraft/search" :fields="fields"
                      @pagination-data="onPaginationData" @loading="startLoading"
                      @loaded="stopLoading" ref="vuetable" :append-params="extraParams"
                      :per-page="resultsPerPage" :css="css.table">
                <template #agent_id="props"><div>
                    <a v-bind:href="'/spacecraft/'+props.rowData.agent_id">{{props.rowData.agent_id}}</a>
                </div></template>
                <template #name="props"><div>
                    <a v-bind:href="'/spacecraft/'+props.rowData.agent_id">{{props.rowData.name}}</a>
                </div></template>
                <template #hull_name="props"><div>
                    <span v-if="props.rowData.hull_id">{{props.rowData.hull_name}}</span>
                    <span v-else class="">-</span>
                </div></template>
                <template #location_name="props"><div>
                    <a v-bind:href="'/locations/'+props.rowData.location_id">{{props.rowData.location_name}}</a>
                </div></template>
                <template #owner_name="props"><div>
                    <a v-bind:href="'/companies/'+props.rowData.owner_id">{{props.rowData.owner_name}}</a>
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
        name: "SpacecraftTable",
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
                    name: 'agent_id',
                    title: 'Id',
                    sortField: 'agent_id',
                    titleClass: '',
                    dataClass: '',
                    width: "10%",

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
                    name: 'hull_name',
                    title: 'Hull Type',
                    sortField: 'hulls.name',
                    titleClass: '',
                    dataClass: '',
                    /*     width: "22%",*/

                },
                {
                    name: 'location_name',
                    title: 'Location',
                    sortField: 'objects.object_name',
                    titleClass: '',
                    dataClass: '',
                    /*  width: "36%",*/

                },
                {
                    name: 'owner_name',
                    title: 'Owner',
                    sortField: 'users.name',
                    titleClass: '',
                    dataClass: '',
                    /*  width: "36%",*/

                },
            ],
            extraParams: {},
            agentName: null,
            agentId: null,
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
                if (this.agentId)
                    params.agent_id = this.agentId;
                if (this.agentName)
                    params.agent_name = this.agentName;

                this.extraParams = params;
                this.$nextTick(() => this.$refs.vuetable.refresh())
            },
            resetFilters() {
                this.agentName = null;
                this.agentId = null;
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
    }
</script>

<style scoped>

</style>
