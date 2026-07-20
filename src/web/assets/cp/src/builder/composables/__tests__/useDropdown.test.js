import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import { useDropdown } from '../useDropdown.js';

/**
 * useDropdown registers document listeners in onMounted, so every case
 * mounts a minimal attached harness component around the composable.
 */
const buildHarness = (config = {}) => {
    let dd = null;
    const wrapper = mount({
        template: '<div ref="rootEl"><button ref="inner" type="button"></button></div>',
        setup() {
            const rootEl = ref(null);
            dd = useDropdown({ root: () => rootEl.value, ...config });

            return { rootEl };
        },
    }, { attachTo: document.body });

    return { wrapper, dd };
};

const mousedownOn = (el) => el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
const documentEscape = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

describe('open / close / toggle', () => {
    it('starts closed; openMenu, close and toggle flip the state', () => {
        const { wrapper, dd } = buildHarness();
        expect(dd.open.value).toBe(false);

        dd.openMenu();
        expect(dd.open.value).toBe(true);

        dd.close();
        expect(dd.open.value).toBe(false);

        dd.toggle();
        expect(dd.open.value).toBe(true);
        dd.toggle();
        expect(dd.open.value).toBe(false);

        wrapper.unmount();
    });

    it('invokes onClose on every close path', () => {
        const onClose = vi.fn();
        const { wrapper, dd } = buildHarness({ onClose });

        dd.openMenu();
        dd.close();
        expect(onClose).toHaveBeenCalledTimes(1);

        dd.toggle();
        dd.toggle();
        expect(onClose).toHaveBeenCalledTimes(2);

        wrapper.unmount();
    });
});

describe('document dismissal', () => {
    it('closes on mousedown outside the root, not on mousedown inside', async () => {
        const { wrapper, dd } = buildHarness();
        dd.openMenu();

        mousedownOn(wrapper.find('button').element);
        await nextTick();
        expect(dd.open.value).toBe(true);

        mousedownOn(document.body);
        await nextTick();
        expect(dd.open.value).toBe(false);

        wrapper.unmount();
    });

    it('ignores outside mousedown while closed', () => {
        const onClose = vi.fn();
        const { wrapper } = buildHarness({ onClose });

        mousedownOn(document.body);
        expect(onClose).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('closes on Escape only while open', () => {
        const onClose = vi.fn();
        const { wrapper, dd } = buildHarness({ onClose });

        documentEscape();
        expect(onClose).not.toHaveBeenCalled();

        dd.openMenu();
        documentEscape();
        expect(dd.open.value).toBe(false);
        expect(onClose).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('drops its document listeners on unmount', () => {
        const onClose = vi.fn();
        const { wrapper, dd } = buildHarness({ onClose });
        dd.openMenu();
        wrapper.unmount();

        documentEscape();
        mousedownOn(document.body);
        expect(onClose).not.toHaveBeenCalled();
        expect(dd.open.value).toBe(true); // untouched after teardown
    });
});

describe('drop direction', () => {
    const rectAt = (top, bottom) => () => ({
        top, bottom, left: 0, right: 200, width: 200, height: bottom - top,
    });

    it('flips up when the space below is under the threshold and above is larger', () => {
        const { wrapper, dd } = buildHarness();
        const ih = window.innerHeight;
        wrapper.element.getBoundingClientRect = rectAt(ih - 40, ih - 6);

        dd.openMenu();
        expect(dd.dropUp.value).toBe(true);

        wrapper.unmount();
    });

    it('stays down when there is room below', () => {
        const { wrapper, dd } = buildHarness();
        wrapper.element.getBoundingClientRect = rectAt(10, 44);

        dd.openMenu();
        expect(dd.dropUp.value).toBe(false);

        wrapper.unmount();
    });

    it('stays down when the space above is even tighter than below', () => {
        const { wrapper, dd } = buildHarness();
        // 30px above, 300px below a small viewport: below is < threshold
        // but flipping up would be worse.
        wrapper.element.getBoundingClientRect = rectAt(30, window.innerHeight - 300);

        dd.openMenu();
        expect(dd.dropUp.value).toBe(false);

        wrapper.unmount();
    });

    it('honors a custom threshold', () => {
        const { wrapper, dd } = buildHarness({ dropUpThreshold: 20 });
        const ih = window.innerHeight;
        // 30px below: under the 340 default but over a 20px threshold.
        wrapper.element.getBoundingClientRect = rectAt(ih - 64, ih - 30);

        dd.openMenu();
        expect(dd.dropUp.value).toBe(false);

        wrapper.unmount();
    });

    it('measures on every open and resets without a root element', () => {
        let rootEl = null;
        let dd = null;
        const wrapper = mount({
            template: '<div></div>',
            setup() {
                dd = useDropdown({ root: () => rootEl });

                return {};
            },
        }, { attachTo: document.body });

        const ih = window.innerHeight;
        rootEl = { getBoundingClientRect: rectAt(ih - 40, ih - 6), contains: () => true };
        dd.openMenu();
        expect(dd.dropUp.value).toBe(true);

        dd.close();
        rootEl = null;
        dd.openMenu();
        expect(dd.dropUp.value).toBe(false);

        wrapper.unmount();
    });
});
