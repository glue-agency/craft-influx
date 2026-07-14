import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DebugItemDetail from '../DebugItemDetail.vue';

/**
 * The shared drill-down pane. Locks in the two-context value-column contract:
 * the debug inspector compares the feed's Incoming value against the element's
 * live Current value, while the log viewer (a historical run, no meaningful
 * "current") shows the raw Incoming value beside the feed's Parsed value —
 * rendered rich via `parsedHtml` (element chips, lightswitches) when present.
 */
const mountDetail = (props = {}) => mount(DebugItemDetail, {
    props: { row: baseRow(), ...props },
    global: { mocks: { $t: (s) => s } },
});

const baseRow = (overrides = {}) => ({
    action: 'updated',
    element: { title: 'Some element' },
    mappings: [
        {
            handle: 'building_type',
            label: 'Building type',
            node: 'building_type.id',
            native: false,
            rawValue: '7',
            parsedValue: 'Werfkelder (#42)',
            currentValue: 'Kelder (#43)',
            changed: true,
        },
    ],
    ...overrides,
});

const headings = (w) => w.findAll('.influx-detail-headings > div').map((d) => d.text());
const values = (w) => w.findAll('.influx-detail-row .influx-detail-val').map((d) => d.text());

describe('DebugItemDetail', () => {
    describe('debug context (default)', () => {
        it('heads the columns Field | Incoming | Current', () => {
            expect(headings(mountDetail())).toEqual(['Field', 'Incoming', 'Current']);
        });

        it('shows the parsed value (raw fallback) as Incoming and the live Current value', () => {
            const [incoming, current] = values(mountDetail());

            expect(incoming).toBe('Werfkelder (#42)');
            expect(current).toBe('Kelder (#43)');
        });

        it('tints the middle column as the Current value', () => {
            const w = mountDetail();

            expect(w.findAll('.influx-detail-row .influx-detail-val')[1].classes()).toContain('influx-detail-val--current');
        });
    });

    describe('log context', () => {
        it('replaces the Current column with Parsed — Field | Incoming | Parsed', () => {
            const h = headings(mountDetail({ context: 'log' }));

            expect(h).toEqual(['Field', 'Incoming', 'Parsed']);
            expect(h).not.toContain('Current');
        });

        it('shows the raw feed value as Incoming and the parsed value as Parsed', () => {
            const [incoming, parsed] = values(mountDetail({ context: 'log' }));

            expect(incoming).toBe('7');
            expect(parsed).toBe('Werfkelder (#42)');
        });

        it('falls back to the raw value in the Parsed column when parsing yields nothing', () => {
            const row = baseRow({
                mappings: [
                    { handle: 'title', label: 'Title', native: true, rawValue: 'Some title', parsedValue: null, currentValue: 'Old title', changed: false },
                ],
            });
            const [incoming, parsed] = values(mountDetail({ row, context: 'log' }));

            expect(incoming).toBe('Some title');
            expect(parsed).toBe('Some title');
        });

        it('drops the Current tint from the middle column', () => {
            const w = mountDetail({ context: 'log' });

            expect(w.findAll('.influx-detail-row .influx-detail-val')[1].classes()).not.toContain('influx-detail-val--current');
        });

        it('renders element chips in the Parsed column when parsedHtml is set', () => {
            const row = baseRow({
                mappings: [
                    {
                        handle: 'building_type',
                        label: 'Building type',
                        node: 'building_type.id',
                        native: false,
                        rawValue: '7',
                        parsedValue: 'Werfkelder (#42)',
                        parsedHtml: '<a class="chip" href="/cp/edit/42">Werfkelder</a>',
                        changed: true,
                    },
                ],
            });
            const w = mountDetail({ row, context: 'log' });
            const rich = w.find('.influx-detail-rich');

            expect(rich.exists()).toBe(true);
            expect(rich.html()).toContain('<a class="chip"');
            expect(rich.text()).toBe('Werfkelder');
            // The plain-text <code> fallback is not rendered in that cell.
            expect(w.findAll('.influx-detail-row .influx-detail-val')[1].find('code').exists()).toBe(false);
        });

        it('renders a lightswitch in the Parsed column when parsedHtml carries one', () => {
            const row = baseRow({
                mappings: [
                    {
                        handle: 'show_in_search',
                        label: 'Show in search',
                        node: 'visible',
                        native: false,
                        rawValue: '1',
                        parsedValue: 'true',
                        parsedHtml: '<button type="button" class="lightswitch small on noteditable" disabled role="switch" aria-checked="true"><div class="lightswitch-container"><div class="handle"></div></div></button>',
                        changed: true,
                    },
                ],
            });
            const w = mountDetail({ row, context: 'log' });
            const rich = w.find('.influx-detail-rich');

            expect(rich.exists()).toBe(true);
            expect(rich.find('button.lightswitch.on').exists()).toBe(true);
            // The 'true' text fallback stays out of the cell.
            expect(w.findAll('.influx-detail-row .influx-detail-val')[1].find('code').exists()).toBe(false);
        });

        it('falls back to the plain parsed text when parsedHtml is null', () => {
            const row = baseRow({
                mappings: [
                    {
                        handle: 'building_type',
                        label: 'Building type',
                        node: 'building_type.id',
                        native: false,
                        rawValue: '7',
                        parsedValue: 'Werfkelder (#42)',
                        parsedHtml: null,
                        changed: true,
                    },
                ],
            });
            const w = mountDetail({ row, context: 'log' });

            expect(w.find('.influx-detail-rich').exists()).toBe(false);
            expect(values(w)[1]).toBe('Werfkelder (#42)');
        });
    });

    describe('missing-node pill', () => {
        const unaddressedRow = () => baseRow({
            mappings: [
                {
                    handle: 'building_type',
                    label: 'Building type',
                    node: 'building_type.id',
                    native: false,
                    rawValue: null,
                    parsedValue: null,
                    currentValue: 'Kelder (#43)',
                    changed: false,
                    unaddressed: true,
                },
            ],
        });

        it('shows a "missing node" pill when the field was unaddressed (both contexts)', () => {
            const debug = mountDetail({ row: unaddressedRow() });
            const log = mountDetail({ row: unaddressedRow(), context: 'log' });

            expect(debug.find('.influx-detail-pill--untouched').text()).toBe('missing node');
            expect(log.find('.influx-detail-pill--untouched').text()).toBe('missing node');
        });

        it('shows no pill for an addressed field', () => {
            expect(mountDetail().find('.influx-detail-pill--untouched').exists()).toBe(false);
        });
    });

    describe('field column node', () => {
        it('shows the feed source node beside the field label', () => {
            const node = mountDetail().find('.influx-detail-node');

            expect(node.exists()).toBe(true);
            expect(node.text()).toBe('building_type.id');
        });

        it('shows no node line for a node-less mapping (its pill says it instead)', () => {
            const row = baseRow({
                mappings: [
                    { handle: 'status', label: 'Status', native: false, rawValue: null, parsedValue: 'x', currentValue: 'x', changed: false, usedDefault: true },
                ],
            });

            expect(mountDetail({ row }).find('.influx-detail-node').exists()).toBe(false);
        });
    });

    describe('use-default pill', () => {
        const defaultedRow = () => baseRow({
            mappings: [
                {
                    handle: 'status',
                    label: 'Status',
                    node: 'status',
                    native: false,
                    rawValue: null,
                    parsedValue: 'for_sale',
                    currentValue: 'for_sale',
                    changed: false,
                    usedDefault: true,
                },
            ],
        });

        it('shows a "use default" pill when the value came from the default (both contexts)', () => {
            const debug = mountDetail({ row: defaultedRow() });
            const log = mountDetail({ row: defaultedRow(), context: 'log' });

            expect(debug.find('.influx-detail-pill--default').text()).toBe('use default');
            expect(log.find('.influx-detail-pill--default').text()).toBe('use default');
        });

        it('shows no pill when the value came from the feed', () => {
            expect(mountDetail().find('.influx-detail-pill--default').exists()).toBe(false);
        });
    });

    describe('managed-by-target pill', () => {
        const managedRow = () => baseRow({
            mappings: [
                {
                    handle: 'groups',
                    label: 'Groups',
                    node: null,
                    native: true,
                    rawValue: null,
                    parsedValue: null,
                    currentValue: null,
                    changed: null,
                    managedByTarget: true,
                },
            ],
        });

        it('shows a "not managed by element" pill when the target owns the attribute', () => {
            expect(mountDetail({ row: managedRow() }).find('.influx-detail-pill--managed').text())
                .toBe('not managed by element');
        });

        it('shows no such pill for a normal field', () => {
            expect(mountDetail().find('.influx-detail-pill--managed').exists()).toBe(false);
        });
    });

    describe('status-pill "why" popover', () => {
        const defaultedRow = () => baseRow({
            mappings: [
                { handle: 'status', label: 'Status', node: 'status', native: false, rawValue: null, parsedValue: 'for_sale', currentValue: 'for_sale', changed: false, usedDefault: true },
            ],
        });

        it('opens an explanation on click and toggles closed on a second click', async () => {
            const w = mountDetail({ row: defaultedRow() });

            expect(w.vm.info).toBe(null);

            await w.find('.influx-detail-pill--default').trigger('click');
            expect(w.vm.info).not.toBe(null);
            expect(w.vm.info.text).toContain('default value');

            await w.find('.influx-detail-pill--default').trigger('click');
            expect(w.vm.info).toBe(null);
        });

        it('closes on Escape', async () => {
            const w = mountDetail({ row: defaultedRow() });

            await w.find('.influx-detail-pill--default').trigger('click');
            expect(w.vm.info).not.toBe(null);

            w.vm.onInfoKeydown({ key: 'Escape' });
            expect(w.vm.info).toBe(null);
        });
    });

    describe('debug context ignores parsedHtml', () => {
        it('never renders the rich markup, always the live Current value', () => {
            const row = baseRow({
                mappings: [
                    {
                        handle: 'building_type',
                        label: 'Building type',
                        node: 'building_type.id',
                        native: false,
                        rawValue: '7',
                        parsedValue: 'Werfkelder (#42)',
                        currentValue: 'Kelder (#43)',
                        parsedHtml: '<a class="chip" href="/cp/edit/42">Werfkelder</a>',
                        changed: true,
                    },
                ],
            });
            const w = mountDetail({ row });

            expect(w.find('.influx-detail-rich').exists()).toBe(false);
            expect(values(w)[1]).toBe('Kelder (#43)');
        });
    });
});
