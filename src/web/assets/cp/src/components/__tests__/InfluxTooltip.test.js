import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import InfluxTooltip from '../InfluxTooltip.vue';

/**
 * THE explanatory tooltip, across both Crafts: 5 registers `<craft-tooltip>`
 * and owns the positioning; 4 ships nothing of the kind and the same sentence
 * has to reach the reader anyway.
 */

const registered = window.customElements.get;

const withCraftTooltip = (fn) => {
    window.customElements.get = (tag) => (tag === 'craft-tooltip' ? class {} : registered.call(window.customElements, tag));
    try {
        fn();
    } finally {
        window.customElements.get = registered;
    }
};

const mountTooltip = (props = {}) => mount(InfluxTooltip, {
    props: { text: 'Why this is flagged.', ...props },
    slots: { default: 'missing mapping' },
});

afterEach(() => {
    window.customElements.get = registered;
});

describe('InfluxTooltip', () => {
    it('hands the sentence to Craft’s own element where the CP registers one', () => {
        withCraftTooltip(() => {
            const wrapper = mountTooltip();

            expect(wrapper.element.tagName.toLowerCase()).toBe('craft-tooltip');
            expect(wrapper.attributes('text')).toBe('Why this is flagged.');
            expect(wrapper.attributes('placement')).toBe('top');
            // No native title beside it — that would be a second tooltip over
            // the first.
            expect(wrapper.find('button').attributes('title')).toBeUndefined();
        });
    });

    it('degrades to an inline span carrying the native title on Craft 4', () => {
        const wrapper = mountTooltip();

        expect(wrapper.element.tagName.toLowerCase()).toBe('span');
        expect(wrapper.find('button').attributes('title')).toBe('Why this is flagged.');
    });

    it('makes the sentence the trigger’s accessible name either way', () => {
        // Craft's tooltip sets no role="tooltip" and no aria-describedby, so a
        // reader who can't see it would otherwise get the bare label.
        expect(mountTooltip().find('button').attributes('aria-label')).toBe('Why this is flagged.');

        withCraftTooltip(() => {
            expect(mountTooltip().find('button').attributes('aria-label')).toBe('Why this is flagged.');
        });
    });

    it('wears the caller’s badge class over a focusable button', () => {
        const button = mountTooltip({ triggerClass: 'pill pill-missing' }).find('button');

        expect(button.classes()).toContain('influx-tooltip-trigger');
        expect(button.classes()).toContain('pill-missing');
        expect(button.attributes('type')).toBe('button');
        expect(button.text()).toBe('missing mapping');
    });

    it('keeps its press off the card header it sits in', async () => {
        // These live in headers that toggle on click AND on keydown.enter/.space
        // (with .prevent) — a trigger without both would collapse the card it
        // explains.
        const seen = [];
        const wrapper = mount({
            components: { InfluxTooltip },
            template: `<div @click="seen.push('click')" @keydown="seen.push('keydown')">
                <InfluxTooltip text="x" />
            </div>`,
            data: () => ({ seen }),
        });

        await wrapper.find('button').trigger('click');
        await wrapper.find('button').trigger('keydown.enter');

        expect(seen).toEqual([]);
    });
});
