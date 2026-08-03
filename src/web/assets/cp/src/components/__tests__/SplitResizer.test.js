import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import SplitResizer from '../SplitResizer.vue';
import { t } from '../../lib/installT.js';

/**
 * The seam handle between a split inspector's two panes. What matters is its
 * contract with the surroundings rather than any rendering of its own: the width
 * lands on the CONTAINER as a custom property (never on the pane, so a
 * swapped-out list can't lose it), the clamp holds at both ends, and a
 * remembered width survives a reload without ever being able to break the mount.
 *
 * The component only ever renders as a direct child of `.influx-split`, right
 * after the list pane, so it's mounted inside exactly that. happy-dom lays
 * nothing out, so the one mock here stands in for layout: a 1000px split whose
 * list pane defaults to 250px — the numbers every assertion is written against.
 */
const PROPERTY = '--influx-split-list-width';
const STORAGE_KEY = 'influx:splitListWidth';

let splitWidth = 1000;
let listWidth = 250;

const Host = {
    template: '<div class="influx-split"><div class="influx-split-list"></div><v-split-resizer /></div>',
    components: { 'v-split-resizer': SplitResizer },
};

// Mounted and settled: the handle samples its surroundings in mounted(), so the
// ARIA values only reach the DOM on the tick after.
const mountResizer = async () => {
    const w = mount(Host, { global: { mocks: { $t: t } } });
    await nextTick();

    return { container: w.find('.influx-split').element, handle: w.find('.influx-split-resizer') };
};

// One pointer drag: down on the handle, move to `to`, release. The capture call
// is stubbed — happy-dom has the method but no pointer to hand over.
const dragTo = async (handle, from, to) => {
    handle.element.setPointerCapture = vi.fn();

    await handle.trigger('pointerdown', { clientX: from, pointerId: 1 });
    await handle.trigger('pointermove', { clientX: to });
    await handle.trigger('pointerup', { clientX: to });
};

const widthOf = (container) => container.style.getPropertyValue(PROPERTY);

describe('SplitResizer', () => {
    beforeEach(() => {
        window.localStorage.clear();
        splitWidth = 1000;
        listWidth = 250;

        vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function rect() {
            return { width: this.classList.contains('influx-split') ? splitWidth : listWidth };
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('semantics', () => {
        it('renders as a labelled vertical separator, focusable', async () => {
            const { handle } = await mountResizer();

            expect(handle.attributes('role')).toBe('separator');
            expect(handle.attributes('aria-orientation')).toBe('vertical');
            expect(handle.attributes('tabindex')).toBe('0');
            expect(handle.attributes('aria-label')).toBe('Resize the item list');
        });

        it('reports the pane it sizes, floored at the minimum and capped at half the split', async () => {
            const { handle } = await mountResizer();

            expect(handle.attributes('aria-valuenow')).toBe('250');
            expect(handle.attributes('aria-valuemin')).toBe('240');
            expect(handle.attributes('aria-valuemax')).toBe('500');
        });

        it('tracks the value as the seam moves', async () => {
            const { handle } = await mountResizer();
            await dragTo(handle, 250, 380);

            expect(handle.attributes('aria-valuenow')).toBe('380');
        });
    });

    describe('pointer drag', () => {
        it('writes the dragged width to the container, not to the pane', async () => {
            const { container, handle } = await mountResizer();
            await dragTo(handle, 250, 400);

            expect(widthOf(container)).toBe('400px');
            expect(container.firstElementChild.style.getPropertyValue(PROPERTY)).toBe('');
        });

        it('takes the pointer captive rather than listening on the document', async () => {
            const { handle } = await mountResizer();
            handle.element.setPointerCapture = vi.fn();

            await handle.trigger('pointerdown', { clientX: 250, pointerId: 7 });

            expect(handle.element.setPointerCapture).toHaveBeenCalledWith(7);
        });

        it('ignores a move that no drag started', async () => {
            const { container, handle } = await mountResizer();
            await handle.trigger('pointermove', { clientX: 700 });

            expect(widthOf(container)).toBe('');
        });

        it('marks the container while dragging, so the panes stop selecting text', async () => {
            const { container, handle } = await mountResizer();
            handle.element.setPointerCapture = vi.fn();

            await handle.trigger('pointerdown', { clientX: 250, pointerId: 1 });
            expect(container.classList.contains('influx-is-resizing')).toBe(true);

            await handle.trigger('pointerup', { clientX: 250 });
            expect(container.classList.contains('influx-is-resizing')).toBe(false);
        });

        it('drops the drag when the capture is lost mid-way', async () => {
            const { container, handle } = await mountResizer();
            handle.element.setPointerCapture = vi.fn();

            await handle.trigger('pointerdown', { clientX: 250, pointerId: 1 });
            await handle.trigger('lostpointercapture');
            await handle.trigger('pointermove', { clientX: 700 });

            expect(container.classList.contains('influx-is-resizing')).toBe(false);
            expect(widthOf(container)).toBe('');
        });
    });

    describe('clamp', () => {
        it('never goes below the minimum, however far left the drag runs', async () => {
            const { container, handle } = await mountResizer();
            await dragTo(handle, 250, -400);

            expect(widthOf(container)).toBe('240px');
        });

        it('never passes half the split, however far right the drag runs', async () => {
            const { container, handle } = await mountResizer();
            await dragTo(handle, 250, 5000);

            expect(widthOf(container)).toBe('500px');
        });

        it('measures the ceiling per drag, so a narrowed window is picked up', async () => {
            const { container, handle } = await mountResizer();
            splitWidth = 600;

            await dragTo(handle, 250, 5000);

            expect(widthOf(container)).toBe('300px');
        });
    });

    describe('keyboard', () => {
        it('steps the seam left and right', async () => {
            const { container, handle } = await mountResizer();

            await handle.trigger('keydown', { key: 'ArrowRight' });
            expect(widthOf(container)).toBe('266px');

            await handle.trigger('keydown', { key: 'ArrowLeft' });
            expect(widthOf(container)).toBe('250px');
        });

        it('steps within the same clamp', async () => {
            listWidth = 245;
            const { container, handle } = await mountResizer();

            await handle.trigger('keydown', { key: 'ArrowLeft' });

            expect(widthOf(container)).toBe('240px');
        });
    });

    describe('reset', () => {
        it('hands the width back to the stylesheet on double-click, and forgets it', async () => {
            const { container, handle } = await mountResizer();
            await dragTo(handle, 250, 400);
            expect(window.localStorage.getItem(STORAGE_KEY)).toBe('400');

            await handle.trigger('dblclick');

            expect(widthOf(container)).toBe('');
            expect(window.localStorage.getItem(STORAGE_KEY)).toBe(null);
            expect(handle.attributes('aria-valuenow')).toBe('250');
        });
    });

    /**
     * The `rail` wiring: the CP details sidebar. The width lands on a rail
     * NAMED by the caller rather than on the handle's own parent, and because
     * that rail sits at the inline-end, the travel is mirrored — dragging back
     * toward the content widens it.
     */
    describe('rail variant', () => {
        const RAIL_PROPERTY = '--details-width';
        const RAIL_KEY = 'influx:detailsWidth';

        let boundsWidth = 1000;
        let railWidth = 350;

        const mountRail = async () => {
            const bounds = document.createElement('div');
            bounds.className = 'influx-bounds';
            const railEl = document.createElement('div');
            bounds.appendChild(railEl);
            document.body.appendChild(bounds);

            const w = mount(SplitResizer, {
                props: {
                    variant: 'rail',
                    target: railEl,
                    cssVar: RAIL_PROPERTY,
                    storageKey: RAIL_KEY,
                    label: 'Resize the details sidebar',
                },
                attachTo: railEl,
                global: { mocks: { $t: t } },
            });
            await nextTick();

            return { railEl, handle: w.find('.influx-rail-resizer') };
        };

        const railWidthOf = (el) => el.style.getPropertyValue(RAIL_PROPERTY);

        beforeEach(() => {
            document.body.innerHTML = '';
            boundsWidth = 1000;
            railWidth = 350;

            vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function rect() {
                return { width: this.classList.contains('influx-bounds') ? boundsWidth : railWidth };
            });
        });

        it('carries its own class and label', async () => {
            const { handle } = await mountRail();

            expect(handle.exists()).toBe(true);
            expect(handle.attributes('aria-label')).toBe('Resize the details sidebar');
            expect(handle.attributes('aria-valuenow')).toBe('350');
            expect(handle.attributes('aria-valuemax')).toBe('500'); // half the bounds
        });

        it('writes the width to the named rail, under the named property', async () => {
            const { railEl, handle } = await mountRail();

            // Toward the inline-start: the rail grows by what the pointer gave back.
            await dragTo(handle, 800, 700);

            expect(railWidthOf(railEl)).toBe('450px');
            expect(window.localStorage.getItem(RAIL_KEY)).toBe('450');
        });

        it('narrows when dragged the other way, down to the floor', async () => {
            const { railEl, handle } = await mountRail();

            await dragTo(handle, 800, 5000);

            expect(railWidthOf(railEl)).toBe('240px');
        });

        it('mirrors the arrow keys too', async () => {
            const { railEl, handle } = await mountRail();

            await handle.trigger('keydown', { key: 'ArrowLeft' });
            expect(railWidthOf(railEl)).toBe('366px');

            await handle.trigger('keydown', { key: 'ArrowRight' });
            expect(railWidthOf(railEl)).toBe('350px');
        });

        it('remembers under its own key, apart from the split list', async () => {
            window.localStorage.setItem(STORAGE_KEY, '420');
            window.localStorage.setItem(RAIL_KEY, '480');

            const { railEl } = await mountRail();

            expect(railWidthOf(railEl)).toBe('480px');
        });
    });

    describe('persistence', () => {
        it('applies a remembered width on mount', async () => {
            window.localStorage.setItem(STORAGE_KEY, '420');
            const { container, handle } = await mountResizer();

            expect(widthOf(container)).toBe('420px');
            expect(handle.attributes('aria-valuenow')).toBe('420');
        });

        it('clamps a remembered width the current split can no longer honour', async () => {
            window.localStorage.setItem(STORAGE_KEY, '900');

            expect(widthOf((await mountResizer()).container)).toBe('500px');
        });

        it('ignores a stored value that is not a width', async () => {
            window.localStorage.setItem(STORAGE_KEY, 'wide');

            expect(widthOf((await mountResizer()).container)).toBe('');
        });

        it('mounts and still resizes where storage throws (private mode)', async () => {
            const denied = () => {
                throw new Error('denied');
            };
            vi.spyOn(window.localStorage, 'getItem').mockImplementation(denied);
            vi.spyOn(window.localStorage, 'setItem').mockImplementation(denied);
            vi.spyOn(window.localStorage, 'removeItem').mockImplementation(denied);

            const { container, handle } = await mountResizer();
            expect(handle.exists()).toBe(true);

            await dragTo(handle, 250, 400);
            expect(widthOf(container)).toBe('400px');

            await handle.trigger('dblclick');
            expect(widthOf(container)).toBe('');
        });
    });
});
