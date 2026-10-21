<template>
    <div class="">
        <div class="vuetable-body-wrapper">
            <table :class="['vuetable', css.tableClass, 'fixed']">
                <colgroup>
                    <col v-for="field in tableFields" :key="field.name"
                         :class="'vuetable-col-' + field.name" :style="{width: field.width}">
                </colgroup>
                <thead>
                <tr>
                    <th v-for="field in tableFields" :key="field.name"
                        :id="hasSlot(field) ? null : '_' + field.name"
                        :class="headerClass(field)"
                        :style="{width: field.width}"
                        v-html="renderTitle(field)"
                        @click="onHeaderClick(field)"></th>
                </tr>
                </thead>
                <tfoot></tfoot>
                <tbody class="vuetable-body">
                <tr v-for="(item, itemIndex) in tableData" :key="itemIndex" :item-index="itemIndex" class="">
                    <template v-for="field in tableFields" :key="field.name">
                        <td v-if="hasSlot(field)" :class="['vuetable-slot', field.dataClass]"
                            :style="{width: field.width}">
                            <slot :name="field.name" :row-data="item" :row-index="itemIndex" :row-field="field"></slot>
                        </td>
                        <td v-else :class="['vuetable-td-' + field.name, field.dataClass]"
                            :style="{width: field.width}"
                            v-html="renderField(field, item)"></td>
                    </template>
                </tr>
                <tr v-if="tableData.length === 0">
                    <td :colspan="tableFields.length" class="vuetable-empty-result">No Data Available</td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script>
    import axios from 'axios';

    // A small replacement for the parts of vuetable-2 this site used, which has
    // no Vue 3 version. It renders the same markup and classes (so the theme's
    // table styles still apply) and talks to the same Laravel endpoints:
    //   GET apiUrl?sort=<sortField>|<asc|desc>&page=N&per_page=N&<appendParams>
    // expecting a Laravel paginator JSON response.
    export default {
        name: 'DataTable',
        props: {
            apiUrl: {type: String, required: true},
            fields: {type: Array, required: true},
            perPage: {type: Number, default: 10},
            appendParams: {type: Object, default: () => ({})},
            css: {type: Object, default: () => ({})},
        },
        emits: ['loading', 'loaded', 'pagination-data'],
        data: () => ({
            tableData: [],
            currentPage: 1,
            sortOrder: null, // {field, sortField, direction}
        }),
        computed: {
            tableFields() {
                return this.fields.map(f => Object.assign({
                    titleClass: '', dataClass: '', sortField: null, formatter: null, width: null,
                }, f));
            },
        },
        mounted() {
            this.loadData();
        },
        methods: {
            // Public API, as used by the table components.
            refresh() {
                this.currentPage = 1;
                return this.loadData();
            },
            changePage(page) {
                this.currentPage = page;
                return this.loadData();
            },

            loadData() {
                const params = Object.assign({
                    sort: this.sortOrder ? this.sortOrder.sortField + '|' + this.sortOrder.direction : '',
                    page: this.currentPage,
                    per_page: this.perPage,
                }, this.appendParams);
                this.$emit('loading');
                return axios.get(this.apiUrl, {params})
                    .then(response => {
                        const body = response.data;
                        this.tableData = body.data || [];
                        this.$emit('pagination-data', body);
                    })
                    .catch(error => {
                        console.error('Failed to load table data', error);
                        this.tableData = [];
                    })
                    .finally(() => this.$emit('loaded'));
            },
            hasSlot(field) {
                return !!this.$slots[field.name];
            },
            isSortable(field) {
                return !!field.sortField;
            },
            isSorted(field) {
                return this.sortOrder && this.sortOrder.field === field.name;
            },
            onHeaderClick(field) {
                if (!this.isSortable(field)) return;
                const direction = this.isSorted(field) && this.sortOrder.direction === 'asc' ? 'desc' : 'asc';
                this.sortOrder = {field: field.name, sortField: field.sortField, direction};
                this.currentPage = 1;
                this.loadData();
            },
            headerClass(field) {
                const base = (this.hasSlot(field) ? 'vuetable-th-slot-' : 'vuetable-th-') + field.name;
                return [
                    base,
                    field.titleClass,
                    this.isSorted(field) ? 'sorted-' + this.sortOrder.direction : '',
                    {sortable: this.isSortable(field)},
                ];
            },
            renderTitle(field) {
                const title = field.title === undefined ? field.name : field.title;
                if (!this.isSortable(field) || !this.css.sortableIcon) return title;
                let icon = this.css.sortableIcon;
                if (this.isSorted(field)) {
                    icon = this.sortOrder.direction === 'asc' ? this.css.ascendingIcon : this.css.descendingIcon;
                }
                return title + ' <i class="sort-icon ' + icon + '" style="opacity:1;position:relative;float:right"></i>';
            },
            renderField(field, item) {
                let value = field.name.split('.').reduce((obj, key) => (obj == null ? obj : obj[key]), item);
                if (value === undefined || value === null) value = '';
                return field.formatter ? field.formatter(value) : value;
            },
        },
    }
</script>

<style>
    .vuetable th.sortable:hover {
        color: #2185d0;
        cursor: pointer;
    }

    .vuetable-empty-result {
        text-align: center;
    }
</style>
