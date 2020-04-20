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
                    <th>
                        Mass
                    </th>
                </tr>
                <tr v-for="resource in filteredResources">
                    <td>
                        {{resource.id}}
                    </td>
                    <td>
                        <a v-bind:href="'/resources/'+resource.id">
                            {{resource.name}}
                        </a>
                    </td>
                    <td>
                        {{resource.description}}
                    </td>
                    <td>
                        {{resource.mass}} Kg
                    </td>
                </tr>
            </table>
        </div>
    </div>
</template>

<script>
    export default {
        name: "ResourcesTable",
        props: {
            resources: Array,
        },
        data() {
            return {
                keyword: null,
                loading: false,
            }
        },
        computed: {
            filteredResources() {
                if (this.keyword == null)
                    return this.resources;
                else {
                    let results = [];
                    this.loading = true;
                    for (let i = 0; i < this.resources.length; i++) {

                        if (this.resources[i].name.toLowerCase().includes(this.keyword.toLowerCase()) || (this.resources[i].description && this.resources[i].description.toLowerCase().includes(this.keyword.toLowerCase())))
                            results.push(this.resources[i]);
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