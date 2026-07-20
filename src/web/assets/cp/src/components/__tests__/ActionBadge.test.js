import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ActionBadge from '../ActionBadge.vue';

/**
 * The shared status pill. Locks in the contract every consumer (DebugItem,
 * LogItem, DebugApp's tag list, LogApp's status pill) relies on: action →
 * palette colour via actionColors.js, an explicit `color` override, slot
 * content over the action text, and class fallthrough so legacy selectors
 * (.influx-debug-tag, …) keep matching the root span.
 */
describe('ActionBadge', () => {
    it('renders the action as the badge text', () => {
        const w = mount(ActionBadge, { props: { action: 'created' } });

        expect(w.text()).toBe('created');
    });

    it('maps the action to its palette colour', () => {
        expect(mount(ActionBadge, { props: { action: 'created' } }).classes()).toContain('live');
        expect(mount(ActionBadge, { props: { action: 'would-skip' } }).classes()).toContain('pending');
        expect(mount(ActionBadge, { props: { action: 'error' } }).classes()).toContain('expired');
    });

    it('lets an explicit color override the action mapping', () => {
        const w = mount(ActionBadge, { props: { action: 'created', color: 'expired' } });

        expect(w.classes()).toContain('expired');
        expect(w.classes()).not.toContain('live');
    });

    it('prefers slot content over the action prop', () => {
        const w = mount(ActionBadge, {
            props: { action: 'created' },
            slots: { default: 'would-create' },
        });

        expect(w.text()).toBe('would-create');
    });

    it('merges a consumer class onto the root span', () => {
        const w = mount(ActionBadge, {
            props: { action: 'created' },
            attrs: { class: 'influx-debug-tag' },
        });

        expect(w.classes()).toContain('influx-debug-tag');
        expect(w.classes()).toContain('influx-action-badge');
    });
});
