import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MappingGroupCard from '../MappingGroupCard.vue';

/**
 * The shared card chrome: a collapsible (or static) header with a `tags` slot
 * and a body. Locks in the slot/variant contract every consumer relies on.
 */
const mountCard = (props = {}, slots = {}) => mount(MappingGroupCard, {
    props,
    slots,
    global: { mocks: { $t: (s) => s } },
});

describe('MappingGroupCard', () => {
    it('renders a chevron + label and toggles collapse when collapsible', async () => {
        const w = mountCard({ label: 'Fields' });

        expect(w.find('.chevron').exists()).toBe(true);
        expect(w.find('.label').text()).toBe('Fields');
        expect(w.classes()).not.toContain('collapsed');

        await w.find('.influx-mapping-group-header').trigger('click');
        expect(w.classes()).toContain('collapsed');
    });

    it('is static — no chevron, no toggle — when collapsible is false', async () => {
        const w = mountCard({ collapsible: false });

        expect(w.find('.chevron').exists()).toBe(false);
        expect(w.classes()).toContain('is-static');

        await w.find('.influx-mapping-group-header').trigger('click');
        expect(w.classes()).not.toContain('collapsed');
    });

    it('honors defaultExpanded=false', () => {
        expect(mountCard({ defaultExpanded: false }).classes()).toContain('collapsed');
    });

    it('renders the tags slot inside the header and adds the variant class', () => {
        const w = mountCard({ variant: 'subfields' }, { tags: '<span class="pill">x</span>' });

        expect(w.classes()).toContain('influx-subfields-group');
        expect(w.find('.influx-mapping-group-header .pill').exists()).toBe(true);
    });

    it('adds the debug variant class', () => {
        expect(mountCard({ variant: 'debug' }).classes()).toContain('influx-debug-item');
    });

    it('lets the header slot fully replace the default header', () => {
        const w = mountCard({}, { header: '<span class="custom-head">hi</span>' });

        expect(w.find('.custom-head').exists()).toBe(true);
        // Default header (chevron) is replaced.
        expect(w.find('.chevron').exists()).toBe(false);
    });

    it('renders body content via the default slot', () => {
        const w = mountCard({}, { default: '<div class="body-x">B</div>' });

        expect(w.find('.influx-mapping-group-body .body-x').exists()).toBe(true);
    });
});
