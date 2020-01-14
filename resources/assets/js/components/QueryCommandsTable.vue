<template>
    <div>

            <div class="row mt-4 mb-4">

                <div class="col-md-3 col-sm-6 col-6">
                    <div class="form-wrap form-sm">
                        <input class="form-input input-md" id="keyword" v-model="keyword"
                               type="text"
                               name="keyword">
                        <label class="form-label rd-input-label" for="keyword">Filter by keyword</label>
                    </div>
                </div>

            </div>

        <div v-bind:class="'mb-4 table-responsive '+(loading?' loading-table ':'') ">
            <table class="table-custom table-custom-bordered">
                <tr>
                    <th>
                        Id
                    </th>
                    <th>
                        Name
                    </th>
                    <th>
                        Description
                    </th>
                </tr>
                <tr v-for="query in filteredQueries">
                    <td>
                        {{query.id}}
                    </td>
                    <td>
                        {{query.name}}
                    </td>
                    <td>
                        {{query.description}}
                    </td>
                </tr>
            </table>
        </div>
    </div>
</template>

<script>
    export default {
        name: "QueryCommandsTable",
        props: {
            queriesList: Array,
        },
        data: () => ({
            keyword: null,
            loading: false,
        }),
        computed: {
            filteredQueries() {
                if (this.keyword == null)
                    return this.queriesList;
                else {
                    let results = [];
                    this.loading = true;
                    for (let i = 0; i < this.queriesList.length; i++) {

                        if (this.queriesList[i].name.toLowerCase().includes(this.keyword.toLowerCase()) || this.queriesList[i].description.toLowerCase().includes(this.keyword.toLowerCase()))
                            results.push(this.queriesList[i]);
                    }
                    this.loading = false;
                    return results;
                }
            },
        },
    }
</script>

<style scoped>

</style>