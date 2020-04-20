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
                <tr v-for="protocol in filteredProtocols">
                    <td>
                        {{protocol.id}}
                    </td>
                    <td>
                        {{protocol.name}}
                    </td>
                    <td>
                        {{protocol.description}}
                    </td>
                </tr>
            </table>
        </div>
    </div>
</template>

<script>
    export default {
        name: "ProtocolsTable",
        props: {
            protocolsList: Array,
            keywordParameter: String,
        },
        created() {
            this.keyword = this.keywordParameter;
        },
        data: () => ({
            keyword: null,
            loading: false,
        }),
        computed: {
            filteredProtocols() {
                if (this.keyword == null)
                    return this.protocolsList;
                else {
                    let results = [];
                    this.loading = true;
                    for (let i = 0; i < this.protocolsList.length; i++) {

                        if (this.protocolsList[i].name.toLowerCase().includes(this.keyword.toLowerCase()) || this.protocolsList[i].description.toLowerCase().includes(this.keyword.toLowerCase()))
                            results.push(this.protocolsList[i]);
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