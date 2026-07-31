import { describe, expect, it } from 'vitest';
import { drillCounts, drillState } from '../drill.js';
import fixture from '../../../tests/fixtures/inspector-row.json';

/**
 * The drill-down's counting rules. They decide what a children-bearing mapping
 * row says about itself — its state label and its wash — so the priority order
 * and the "no children" cases are pinned here rather than in the component.
 */

const row = (overrides = {}) => ({
    handle: 'title',
    label: 'Title',
    changed: false,
    unaddressed: false,
    error: null,
    children: null,
    childrenType: null,
    ...overrides,
});

const child = (action, mappings = []) => ({
    title: 'Tekst',
    blockType: 'text',
    element: null,
    action,
    mappings,
});

describe('drillCounts', () => {
    it('counts an errored child once for itself and once per errored field inside it', () => {
        const counts = drillCounts(row({
            children: [
                child('error', [row({ error: 'Strategy blew up' })]),
                child('unchanged', [row()]),
            ],
        }));

        expect(counts.errors).toBe(2);
    });

    it('counts the unaddressed field rows across every child as missing nodes', () => {
        const counts = drillCounts(row({
            children: [
                child('would-update', [row({ unaddressed: true }), row()]),
                child('unchanged', [row({ unaddressed: true })]),
            ],
        }));

        expect(counts.missing).toBe(2);
    });

    it('counts every child whose action is not "unchanged" as a change', () => {
        const counts = drillCounts(row({
            children: [
                child('would-add'),
                child('would-remove'),
                child('would-create'),
                child('would-update'),
                child('unchanged'),
            ],
        }));

        expect(counts.changes).toBe(4);
    });

    it('reads a row that drills into nothing as zeroes', () => {
        expect(drillCounts(row())).toEqual({ errors: 0, missing: 0, changes: 0 });
        expect(drillCounts(row({ children: [] }))).toEqual({ errors: 0, missing: 0, changes: 0 });
        expect(drillCounts(null)).toEqual({ errors: 0, missing: 0, changes: 0 });
    });
});

describe('drillState', () => {
    const state = (children) => drillState(row({ children }));

    it('reads an error over a missing node over a change', () => {
        const errored = child('error', [row({ unaddressed: true, changed: true })]);

        expect(state([errored, child('would-add')])).toBe('error');
        expect(state([child('would-add', [row({ unaddressed: true })])])).toBe('warn');
        expect(state([child('would-add')])).toBe('changed');
    });

    it('reads a drill into nothing of note as no state at all', () => {
        expect(state([child('unchanged', [row()])])).toBe(null);
    });

    it('reads a row with no children as no state at all', () => {
        expect(drillState(row())).toBe(null);
        expect(drillState(row({ children: [] }))).toBe(null);
        expect(drillState(null)).toBe(null);
    });
});

describe('the wire fixture', () => {
    const byHandle = (handle) => fixture.mappings.find((m) => m.handle === handle);

    it('reads the blocks row as one change — an unchanged block beside an added one', () => {
        const blocks = byHandle('content_blocks');

        expect(drillCounts(blocks)).toEqual({ errors: 0, missing: 0, changes: 1 });
        expect(drillState(blocks)).toBe('changed');
    });

    it('reads the relation row as a change even though the row itself is unchanged', () => {
        const related = byHandle('related_projects');

        expect(related.changed).toBe(false);
        expect(drillCounts(related)).toEqual({ errors: 0, missing: 0, changes: 1 });
        expect(drillState(related)).toBe('changed');
    });

    it('reads a plain row as no drill at all', () => {
        expect(drillState(byHandle('title'))).toBe(null);
    });
});
