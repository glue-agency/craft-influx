import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DebugItemDetail from '../DebugItemDetail.vue';
import fixture from '../../../tests/fixtures/inspector-row.json';

/**
 * The JS half of the inspector-row contract test. PHP is the authority —
 * InspectorService::itemRow() for the item envelope and
 * ItemRowPresenter::presentMappingResults() for its `mappings` rows, both
 * asserted against the same fixture by InspectorRowPayloadTest. This side pins
 * the keys DebugItemDetail.vue reads, in both contexts it renders.
 *
 * The fixture's `parsedHtml` is null throughout (only the log drill-down fills
 * it, server-side); the rich-render branch is covered by overriding the key
 * locally, so the contract "key always present, value usually null" is asserted
 * end to end.
 *
 * A row that nests elements carries them under `children`, each child holding
 * its own `mappings` in this very same row shape — so the keys are asserted at
 * every depth the fixture goes.
 */

const ROW_KEYS = [
    'matchAttribute', 'matchNode', 'matchValue', 'element', 'isNew',
    'action', 'message', 'raw', 'mappings', 'error',
].sort();

const MAPPING_KEYS = [
    'handle', 'label', 'node', 'default', 'native', 'rawValue', 'parsedValue',
    'parsedHtml', 'currentValue', 'changed', 'unaddressed', 'usedDefault',
    'managedByTarget', 'error', 'children', 'childrenType',
].sort();

const CHILD_KEYS = ['title', 'blockType', 'element', 'action', 'mappings'].sort();

// Every child of every row, recursively.
const childrenOf = (mappings) => mappings.flatMap(
    (m) => (m.children ?? []).flatMap((c) => [c, ...childrenOf(c.mappings)]),
);

const mountDetail = (props = {}) => mount(DebugItemDetail, {
    props: { row: fixture, matchAttribute: fixture.matchAttribute, ...props },
    global: { mocks: { $t: (s) => s } },
});

describe('Inspector row wire contract', () => {
    it('carries exactly the item keys the drill-down reads', () => {
        expect(Object.keys(fixture).sort()).toEqual(ROW_KEYS);
    });

    it('carries exactly the mapping-row keys on every row', () => {
        expect(fixture.mappings.length).toBeGreaterThan(1);

        for (const mapping of fixture.mappings) {
            expect(Object.keys(mapping).sort()).toEqual(MAPPING_KEYS);
        }
    });

    it('carries exactly the child keys on every nested child, at every depth', () => {
        const children = childrenOf(fixture.mappings);

        expect(children.length).toBeGreaterThan(1);

        for (const child of children) {
            expect(Object.keys(child).sort()).toEqual(CHILD_KEYS);

            // A child's own rows are rows — same keys, same reader.
            for (const mapping of child.mappings) {
                expect(Object.keys(mapping).sort()).toEqual(MAPPING_KEYS);
                expect(mapping.children === null || Array.isArray(mapping.children)).toBe(true);
            }
        }
    });

    it('pins every child flavour — a saved element, one the sync would only add, and a table row', () => {
        const children = childrenOf(fixture.mappings);
        const blocks = children.filter((c) => c.blockType !== null);
        const related = children.find((c) => c.blockType === null && c.element !== null);

        // A block that already exists is a saved element, chip and all.
        expect(typeof blocks[0].element.chipHtml).toBe('string');
        expect(typeof blocks[0].title).toBe('string');
        expect(typeof blocks[0].action).toBe('string');

        // One the sync would add has neither yet — the pane labels it by its
        // ordinal, so both keys have to be nullable.
        expect(blocks[1].element).toBe(null);
        expect(blocks[1].title).toBe(null);

        expect(typeof related.element.chipHtml).toBe('string');
        expect(typeof related.element.id).toBe('number');

        // A table row is no element at all and never carries either key, yet its
        // cells are still labelled — from the map the server built off the
        // field's columns.
        const rows = childrenOf(fixture.mappings.filter((m) => m.childrenType === 'rows'));

        expect(rows.length).toBeGreaterThan(1);

        for (const row of rows) {
            expect(row.element).toBe(null);
            expect(row.title).toBe(null);
            expect(row.mappings.every((m) => typeof m.label === 'string' && m.label !== '')).toBe(true);
        }
    });

    it('names the child count noun only alongside children', () => {
        for (const mapping of fixture.mappings) {
            expect(mapping.childrenType === null).toBe(mapping.children === null);
        }
    });

    it('shapes the values the component assumes', () => {
        expect(typeof fixture.action).toBe('string');
        expect(typeof fixture.isNew).toBe('boolean');
        expect(typeof fixture.element.chipHtml).toBe('string');
        expect(typeof fixture.raw).toBe('object');

        for (const mapping of fixture.mappings) {
            expect(typeof mapping.handle).toBe('string');
            expect(typeof mapping.label).toBe('string');
            expect(typeof mapping.native).toBe('boolean');
            expect(typeof mapping.unaddressed).toBe('boolean');
            expect(typeof mapping.usedDefault).toBe('boolean');
            expect(typeof mapping.managedByTarget).toBe('boolean');
            // Values arrive already stringified (or null) — never objects.
            for (const key of ['rawValue', 'parsedValue', 'currentValue', 'parsedHtml', 'error']) {
                expect(['string', 'object']).toContain(typeof mapping[key]);
            }
            expect(mapping.children === null || Array.isArray(mapping.children)).toBe(true);
        }
    });
});

describe('DebugItemDetail renders the contract', () => {
    it('heads the element chip, action badge and one row per mapping', () => {
        const w = mountDetail();

        expect(w.find('.influx-detail-chip').html()).toContain('Werfkelder');
        expect(w.findAll('.influx-detail-row')).toHaveLength(fixture.mappings.length);
        // The pills read `matchAttribute` against each row's handle, plus the
        // row's own unaddressed / usedDefault / managedByTarget flags. The
        // children-bearing rows keep their field cell too — what changes is
        // their value cells, which is the component's own concern (see
        // DebugItemDetail.test.js), not the contract's.
        expect(w.findAll('.influx-detail-field-name').map((n) => n.text())).toEqual([
            'Import ID match by',
            'Title',
            'Building type missing node',
            'Content blocks',
            'Related projects',
            'Specs',
        ]);
    });

    it('reads node, values and the changed tint off the row', () => {
        const w = mountDetail();
        const changed = w.findAll('.influx-detail-row')[1];

        expect(changed.find('code.influx-detail-node').text()).toBe('title');
        expect(changed.attributes('data-changed')).toBe('true');
        expect(changed.findAll('.influx-detail-val').map((v) => v.text())).toEqual(['Werfkelder', 'Kelder']);
    });

    it('surfaces a mapping error as its own band', () => {
        const w = mountDetail();

        expect(w.find('.influx-detail-field-error').text()).toBe(fixture.mappings[2].error);
    });

    it('swaps the Current column for Parsed in the log context', () => {
        const w = mountDetail({ context: 'log' });

        expect(w.findAll('.influx-detail-headings > div').map((d) => d.text())).toEqual(['Field', 'Incoming', 'Parsed']);
    });

    it('renders parsedHtml rich in the log context when the server filled it', () => {
        const row = {
            ...fixture,
            mappings: [{ ...fixture.mappings[0], parsedHtml: '<span class="chip">Werfkelder</span>' }],
        };
        const w = mountDetail({ row, context: 'log' });

        expect(w.find('.influx-detail-rich').html()).toContain('chip');
    });

    it('renders the raw payload from `raw`', async () => {
        const w = mountDetail();
        await w.findAll('.influx-detail-toggle .btn')[1].trigger('click');

        expect(w.find('.influx-detail-raw').text()).toContain('"building_type"');
    });
});
