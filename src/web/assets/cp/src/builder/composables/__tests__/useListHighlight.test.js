import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { useListHighlight } from '../useListHighlight.js';

const build = (initialCount = 3) => {
    const items = ref(Array.from({ length: initialCount }, (_, i) => i));
    const lh = useListHighlight({ count: () => items.value.length });

    return { lh, items };
};

const keyEvent = (key) => ({ key, preventDefault: vi.fn() });

describe('moveHighlight', () => {
    it('wraps around both ends', () => {
        const { lh } = build(3);

        lh.moveHighlight(-1);
        expect(lh.highlightedIndex.value).toBe(2);

        lh.moveHighlight(1);
        expect(lh.highlightedIndex.value).toBe(0);

        lh.moveHighlight(1);
        expect(lh.highlightedIndex.value).toBe(1);
    });

    it('is a no-op on an empty list', () => {
        const { lh } = build(0);

        lh.moveHighlight(1);
        expect(lh.highlightedIndex.value).toBe(0);
    });
});

describe('reset + shrink clamp', () => {
    it('reset seeds the index, defaulting to 0', () => {
        const { lh } = build(5);

        lh.reset(3);
        expect(lh.highlightedIndex.value).toBe(3);

        lh.reset();
        expect(lh.highlightedIndex.value).toBe(0);
    });

    it('resets to 0 when the list shrinks past the highlight', async () => {
        const { lh, items } = build(3);
        lh.reset(2);

        items.value = [0];
        await nextTick();
        expect(lh.highlightedIndex.value).toBe(0);
    });

    it('keeps the highlight when the list shrinks but still covers it', async () => {
        const { lh, items } = build(3);
        lh.reset(1);

        items.value = [0, 1];
        await nextTick();
        expect(lh.highlightedIndex.value).toBe(1);
    });
});

describe('onListKeydown', () => {
    it('handles ArrowDown / ArrowUp with preventDefault and a move', () => {
        const { lh } = build(3);
        const handlers = { commit: vi.fn(), close: vi.fn() };

        const down = keyEvent('ArrowDown');
        expect(lh.onListKeydown(down, handlers)).toBe(true);
        expect(down.preventDefault).toHaveBeenCalled();
        expect(lh.highlightedIndex.value).toBe(1);

        const up = keyEvent('ArrowUp');
        expect(lh.onListKeydown(up, handlers)).toBe(true);
        expect(up.preventDefault).toHaveBeenCalled();
        expect(lh.highlightedIndex.value).toBe(0);

        expect(handlers.commit).not.toHaveBeenCalled();
        expect(handlers.close).not.toHaveBeenCalled();
    });

    it('handles Enter → commit and Escape → close', () => {
        const { lh } = build(3);
        const handlers = { commit: vi.fn(), close: vi.fn() };

        const enter = keyEvent('Enter');
        expect(lh.onListKeydown(enter, handlers)).toBe(true);
        expect(enter.preventDefault).toHaveBeenCalled();
        expect(handlers.commit).toHaveBeenCalledTimes(1);

        const esc = keyEvent('Escape');
        expect(lh.onListKeydown(esc, handlers)).toBe(true);
        expect(esc.preventDefault).toHaveBeenCalled();
        expect(handlers.close).toHaveBeenCalledTimes(1);
    });

    it.each(['Backspace', 'Delete', 'Tab', 'a'])('leaves %j unhandled for caller extras', (key) => {
        const { lh } = build(3);
        const handlers = { commit: vi.fn(), close: vi.fn() };

        const e = keyEvent(key);
        expect(lh.onListKeydown(e, handlers)).toBe(false);
        expect(e.preventDefault).not.toHaveBeenCalled();
        expect(handlers.commit).not.toHaveBeenCalled();
        expect(handlers.close).not.toHaveBeenCalled();
    });
});

describe('scrollHighlightedIntoView', () => {
    const buildDom = () => {
        document.body.innerHTML = `
            <div id="root">
                <div data-influx-scroll>
                    <ul>
                        <li data-flat-idx="0"></li>
                        <li data-flat-idx="1"></li>
                    </ul>
                </div>
            </div>`;
        const scroller = document.querySelector('[data-influx-scroll]');
        // happy-dom's layoutless scrollTop may clamp; a plain stored value
        // keeps the math observable.
        let scrollTop = 50;
        Object.defineProperty(scroller, 'scrollTop', {
            configurable: true,
            get: () => scrollTop,
            set: (v) => { scrollTop = v; },
        });
        scroller.getBoundingClientRect = () => ({ top: 0, bottom: 100 });

        return { root: document.getElementById('root'), scroller };
    };

    it('scrolls down just enough when the row sits below the viewport', () => {
        const { root, scroller } = buildDom();
        const row = root.querySelector('[data-flat-idx="1"]');
        row.getBoundingClientRect = () => ({ top: 120, bottom: 140 });

        const { lh } = build(2);
        lh.reset(1);
        lh.scrollHighlightedIntoView(root);
        expect(scroller.scrollTop).toBe(90); // 50 + (140 - 100)
    });

    it('scrolls up just enough when the row sits above the viewport', () => {
        const { root, scroller } = buildDom();
        const row = root.querySelector('[data-flat-idx="0"]');
        row.getBoundingClientRect = () => ({ top: -30, bottom: -10 });

        const { lh } = build(2);
        lh.scrollHighlightedIntoView(root);
        expect(scroller.scrollTop).toBe(20); // 50 + (-30 - 0)
    });

    it('leaves the scroller alone when the row is already visible', () => {
        const { root, scroller } = buildDom();
        const row = root.querySelector('[data-flat-idx="0"]');
        row.getBoundingClientRect = () => ({ top: 10, bottom: 30 });

        const { lh } = build(2);
        lh.scrollHighlightedIntoView(root);
        expect(scroller.scrollTop).toBe(50);
    });

    it('no-ops without a root, a matching row, or a scroll container', () => {
        const { root } = buildDom();
        const { lh } = build(2);

        expect(() => lh.scrollHighlightedIntoView(null)).not.toThrow();

        lh.reset(9); // no row carries this index
        expect(() => lh.scrollHighlightedIntoView(root)).not.toThrow();

        document.body.innerHTML = '<div id="bare"><span data-flat-idx="0"></span></div>';
        lh.reset(0);
        expect(() => lh.scrollHighlightedIntoView(document.getElementById('bare'))).not.toThrow();
    });
});
