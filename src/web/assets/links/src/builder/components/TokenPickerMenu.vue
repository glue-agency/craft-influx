<template>
    <div class="influx-tokenized-picker-menu" role="menu" data-influx-scroll>
        <!-- Search input only shows for manually-opened pickers; when
             triggered by a keystroke in the URL itself, that input IS the
             search and a second one would be confusing. -->
        <div v-if="showSearch" class="influx-tokenized-picker-search">
            <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
                <circle cx="5" cy="5" r="3.25" stroke="currentColor" stroke-width="1.2" fill="none"/>
                <path d="M7.5 7.5l3 3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
            <input
                ref="searchInput"
                type="text"
                :value="searchQuery"
                :placeholder="$t('Filter tokens…')"
                spellcheck="false"
                autocomplete="off"
                @input="$emit('update:searchQuery', $event.target.value)"
                @keydown="$emit('search-keydown', $event)"
            />
        </div>

        <p v-if="!filteredGroups.length" class="influx-tokenized-picker-empty">
            {{ $t('No matches for') }} <code>{{ effectiveQuery }}</code>.
        </p>

        <template v-for="group in filteredGroups" :key="group._gid">
            <h6 v-if="group.label">{{ group.label }}</h6>
            <ul class="influx-token-group" :data-kind="group.kind || 'custom'">
                <li v-for="item in group.data" :key="item.name">
                    <button
                        type="button"
                        class="influx-tokenized-picker-item"
                        :class="{ highlighted: highlightedIndex === item._flatIdx }"
                        role="menuitem"
                        :data-flat-idx="item._flatIdx"
                        @mousemove="$emit('highlight', item._flatIdx)"
                        @mousedown.prevent
                        @click="$emit('select', item)"
                    >
                        <span class="influx-tokenized-chip-inline" :data-kind="group.kind || 'custom'">{{ item.name }}</span>
                        <span v-if="item.hint" class="hint">{{ item.hint }}</span>
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>

<script>
/**
 * The picker dropdown: optional search input, grouped items, highlight.
 * Purely presentational — all state lives in useTokenPicker; the parent
 * wires the emits back into it.
 */
export default {
    name: 'TokenPickerMenu',

    props: {
        filteredGroups: { type: Array, required: true },
        highlightedIndex: { type: Number, default: 0 },
        effectiveQuery: { type: String, default: '' },
        showSearch: { type: Boolean, default: false },
        searchQuery: { type: String, default: '' },
    },

    emits: ['select', 'highlight', 'update:searchQuery', 'search-keydown'],

    mounted() {
        // The menu mounts fresh on every open; manual mode autofocuses its
        // search input (replaces the parent's old manualPickerOpen watcher).
        if (this.showSearch) {
            this.$refs.searchInput?.focus();
        }
    },
};
</script>
