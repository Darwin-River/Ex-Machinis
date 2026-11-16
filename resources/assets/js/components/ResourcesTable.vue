<template>
    <div>
        <div class="row mt-4 mb-4">
            <div class="col-md-3 col-sm-6 col-6">
                <div class="form-wrap form-sm">
                    <input class="form-input input-md" id="keyword" v-model="keyword"
                           type="text"
                           name="keyword">
                    <label class="form-label" for="keyword">Filter by keyword</label>
                </div>
            </div>

        </div>
        <div v-bind:class="'mb-4 table-responsive '+(loading?' loading-table ':'')">
            <table class="table-custom table-custom-bordered">
                <tr>
                    <th class="resource-thumb"><span class="sr-only">Picture</span></th>
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
                    <td class="resource-thumb">
                        <a v-if="resource.thumb" v-bind:href="'/resources/'+resource.id" tabindex="-1" aria-hidden="true">
                            <img v-bind:src="resource.thumb" alt="" width="48" height="48" loading="lazy">
                        </a>
                    </td>
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
                        {{resource.mass}} kg
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
    /* The resource's thumbnail (#707): a still of its turntable */
    .resource-thumb { width: 64px; padding-top: 4px; padding-bottom: 4px; }
    .resource-thumb img { display: block; width: 48px; height: 48px; }
</style>