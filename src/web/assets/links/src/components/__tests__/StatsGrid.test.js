import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import StatsGrid from '../StatsGrid.vue';
import StatCell from '../StatCell.vue';

/**
 * The shared facts grid + cell pair. Locks in the contract the log viewer and
 * debug inspector rely on: the `tag` prop (DebugApp's controls row is a form
 * whose submit re-inspects), the divided/align-top modifiers, label+value
 * cells, the slot fallback for cells with markup, and 0 rendering as a value
 * (the counters).
 */
const mountGrid = (template) => mount({
    components: { StatsGrid, StatCell },
    template,
});

describe('StatsGrid + StatCell', () => {
    it('renders a div by default and the tag prop element when given', () => {
        expect(mountGrid('<stats-grid />').find('div.influx-stats-grid').exists()).toBe(true);
        expect(mountGrid('<stats-grid tag="form" />').find('form.influx-stats-grid').exists()).toBe(true);
    });

    it('adds the divided and align-top modifier classes', () => {
        const w = mountGrid('<stats-grid divided align-top />').find('.influx-stats-grid');

        expect(w.classes()).toContain('influx-stats-grid--divided');
        expect(w.classes()).toContain('influx-stats-grid--align-top');
    });

    it('renders cells with an eyebrow label and a value', () => {
        const w = mountGrid('<stats-grid><stat-cell label="Trigger" value="cp" /></stats-grid>');

        expect(w.find('.influx-stat-eyebrow').text()).toBe('Trigger');
        expect(w.find('.influx-stat-value').text()).toBe('cp');
    });

    it('renders 0 as a real value (the counters)', () => {
        const w = mountGrid('<stats-grid><stat-cell label="Deleted" :value="0" /></stats-grid>');

        expect(w.find('.influx-stat-value').text()).toBe('0');
    });

    it('falls back to the default slot when no value is given', () => {
        const w = mountGrid('<stats-grid><stat-cell label="Actions"><em class="x">pills</em></stat-cell></stats-grid>');

        expect(w.find('.influx-stat-value').exists()).toBe(false);
        expect(w.find('.influx-stat-cell .x').text()).toBe('pills');
    });

    it('merges consumer classes onto the grid and cell roots', () => {
        const w = mountGrid('<stats-grid class="influx-feed-controls"><stat-cell class="influx-feed-cell" label="Site" /></stats-grid>');

        expect(w.find('.influx-stats-grid').classes()).toContain('influx-feed-controls');
        expect(w.find('.influx-stat-cell').classes()).toContain('influx-feed-cell');
    });
});
