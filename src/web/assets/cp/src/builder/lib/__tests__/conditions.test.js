import { describe, expect, it } from 'vitest';

import { isVisible } from '../conditions.js';

/**
 * The showIf grammar both schema renderers share. `resolve` stands in for
 * "the saved value, falling back to the node's declared default" — the callers
 * differ in where they read from, never in what the conditions mean.
 */
describe('isVisible', () => {
    const resolve = (values) => (handle) => values[handle];

    it('shows a node that declares no conditions', () => {
        expect(isVisible({ handle: 'a' }, resolve({}))).toBe(true);
        expect(isVisible({ handle: 'a', showIf: [] }, resolve({}))).toBe(true);
    });

    it('tolerates a missing node', () => {
        expect(isVisible(undefined, resolve({}))).toBe(true);
    });

    it('matches an exact value', () => {
        const node = { showIf: [{ handle: 'mode', equals: 'url' }] };

        expect(isVisible(node, resolve({ mode: 'url' }))).toBe(true);
        expect(isVisible(node, resolve({ mode: 'id' }))).toBe(false);
        expect(isVisible(node, resolve({}))).toBe(false);
    });

    it('matches any of a set', () => {
        const node = { showIf: [{ handle: 'blockSource', in: ['listByKey', 'listByNode'] }] };

        expect(isVisible(node, resolve({ blockSource: 'listByKey' }))).toBe(true);
        expect(isVisible(node, resolve({ blockSource: 'listByNode' }))).toBe(true);
        expect(isVisible(node, resolve({ blockSource: 'grouped' }))).toBe(false);
        expect(isVisible(node, resolve({}))).toBe(false);
    });

    it('treats an empty or absent set as matching nothing', () => {
        expect(isVisible({ showIf: [{ handle: 'a', in: [] }] }, resolve({ a: 'x' }))).toBe(false);
    });

    it('falls back to a truthiness test when no test is named', () => {
        const node = { showIf: [{ handle: 'upload' }] };

        expect(isVisible(node, resolve({ upload: true }))).toBe(true);
        expect(isVisible(node, resolve({ upload: false }))).toBe(false);
        expect(isVisible(node, resolve({}))).toBe(false);
    });

    it('ANDs every condition', () => {
        const node = {
            showIf: [
                { handle: 'mode', equals: 'url' },
                { handle: 'upload' },
            ],
        };

        expect(isVisible(node, resolve({ mode: 'url', upload: true }))).toBe(true);
        expect(isVisible(node, resolve({ mode: 'url', upload: false }))).toBe(false);
        expect(isVisible(node, resolve({ mode: 'id', upload: true }))).toBe(false);
    });

    /**
     * `equals: false` must test equality, not fall through to truthiness — a
     * lightswitch gated on being OFF is the case that would break.
     */
    it('distinguishes an explicit false from an absent test', () => {
        const node = { showIf: [{ handle: 'flag', equals: false }] };

        expect(isVisible(node, resolve({ flag: false }))).toBe(true);
        expect(isVisible(node, resolve({ flag: true }))).toBe(false);
    });
});
