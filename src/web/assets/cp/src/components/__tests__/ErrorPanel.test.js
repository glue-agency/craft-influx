import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ErrorPanel from '../ErrorPanel.vue';

/**
 * The shared preformatted-error block. The band chrome differs per consumer,
 * so the contract is just: the error text lands in a <pre>, and the legacy
 * band class (.influx-log-error / .influx-feed-error) falls through to the
 * root so each app's chrome and the existing test selectors keep matching.
 */
describe('ErrorPanel', () => {
    it('renders the error text inside a pre', () => {
        const w = mount(ErrorPanel, { props: { error: 'boom\nline two' } });

        expect(w.find('pre').text()).toBe('boom\nline two');
    });

    it('merges a consumer class onto the root panel', () => {
        const w = mount(ErrorPanel, {
            props: { error: 'boom' },
            attrs: { class: 'influx-feed-error' },
        });

        expect(w.classes()).toContain('influx-feed-error');
        expect(w.classes()).toContain('influx-error-panel');
    });
});
