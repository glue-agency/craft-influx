<template>
    <div class="influx-log-filter">
        <button
            type="button"
            class="btn menubtn"
            :class="{ active: open }"
            aria-haspopup="true"
            :aria-expanded="open ? 'true' : 'false'"
            @click.stop="open = !open"
        >
            {{ label }}
        </button>

        <!-- Craft element-index-style status menu: each action toggles its own
             visibility, a checkmark marks the active ones, and the menu stays
             open for multi-select. -->
        <div v-if="open" class="influx-log-filter-menu">
            <ul>
                <li v-for="f in filterDefs" :key="f.action">
                    <button
                        type="button"
                        class="influx-log-filter-option"
                        :class="{ 'is-active': activeFilters[f.action] }"
                        @click.stop="$emit('toggle', f.action)"
                    >
                        <span class="status" :class="f.color"></span>
                        <span class="influx-log-filter-label">{{ $t(f.action) }}</span>
                        <span class="influx-log-filter-count">{{ filterCounts[f.action] || 0 }}</span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<script>
/**
 * The run-log action filter, as a Craft element-index-style status dropdown:
 * a menu button whose menu lists every action with a checkmark when active, a
 * status-coloured dot, and the current count. Multi-select — the menu stays
 * open and each click toggles one action's visibility via the `toggle` event.
 */
export default {
    name: 'LogFilterMenu',

    props: {
        filterDefs: { type: Array, required: true },
        activeFilters: { type: Object, required: true },
        filterCounts: { type: Object, required: true },
    },

    emits: ['toggle'],

    data() {
        return {
            open: false,
        };
    },

    computed: {
        // Summarise the filter state on the trigger, mirroring the element
        // index's status button (which shows the active filter).
        label() {
            const total = this.filterDefs.length;
            const active = this.filterDefs.filter((f) => this.activeFilters[f.action]).length;

            if (active === total) {
                return this.$t('All actions');
            }

            if (active === 0) {
                return this.$t('No actions');
            }

            return this.$t('{n} of {total} actions', { n: active, total });
        },
    },

    mounted() {
        // Dismiss on outside click / Escape, like a native CP menu.
        this._onDocClick = (e) => {
            if (this.open && ! this.$el.contains(e.target)) {
                this.open = false;
            }
        };
        this._onKey = (e) => {
            if (e.key === 'Escape') {
                this.open = false;
            }
        };
        document.addEventListener('click', this._onDocClick);
        document.addEventListener('keydown', this._onKey);
    },

    beforeUnmount() {
        document.removeEventListener('click', this._onDocClick);
        document.removeEventListener('keydown', this._onKey);
    },
};
</script>

<style scoped>
.influx-log-filter {
    position: relative;
    display: inline-block;
    margin: 0 0 14px;
}

/* Menu panel — Craft-menu chrome (white card, hairline-ish shadow, rounded). */
.influx-log-filter-menu {
    position: absolute;
    top: calc(100% + 3px);
    left: 0;
    z-index: 100;
    min-width: 190px;
    padding: 4px;
    background: var(--white);
    border-radius: var(--menu-border-radius, 5px);
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15), 0 0 1px rgba(0, 0, 0, 0.25);
}

.influx-log-filter-menu ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.influx-log-filter-option {
    position: relative;
    display: flex;
    align-items: center;
    gap: 7px;
    width: 100%;
    padding: 6px 10px 6px 24px;
    border: 0;
    border-radius: 4px;
    background: none;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
}

.influx-log-filter-option:hover { background: var(--gray-100); }

/* Checkmark in the left gutter for active actions, like a selected menu row. */
.influx-log-filter-option.is-active::before {
    content: '✓';
    position: absolute;
    left: 8px;
    font-size: 11px;
    color: var(--enabled-color);
}

.influx-log-filter-label { color: var(--text-color); }

.influx-log-filter-count {
    margin-left: auto;
    padding-left: 12px;
    color: var(--light-text-color);
    font-variant-numeric: tabular-nums;
}
</style>
