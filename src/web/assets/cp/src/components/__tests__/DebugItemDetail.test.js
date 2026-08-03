import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DebugItemDetail from '../DebugItemDetail.vue';
import { t } from '../../lib/installT.js';

/**
 * The shared drill-down pane. Locks in the two-context value-column contract:
 * the debug inspector compares the feed's Incoming value against the element's
 * live Current value, while the log viewer (a historical run, no meaningful
 * "current") shows the raw Incoming value beside the feed's Parsed value —
 * rendered rich via `parsedHtml` (element chips, lightswitches) when present.
 *
 * Plus the drill affordance on a row that nests elements, and the `drilled`
 * mode the host re-mounts the component in for the child a reader picks.
 *
 * `$t` is the real helper (setup.js stubs the Craft.t it delegates to), so the
 * counted labels are asserted interpolated rather than as templates.
 */
const mountDetail = (props = {}) => mount(DebugItemDetail, {
    props: { row: baseRow(), ...props },
    global: { mocks: { $t: t } },
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

const child = (action, mappings = []) => ({ title: 'Tekst', blockType: 'text', element: null, action, mappings });

// A row that nests elements: the value cells stay filled on purpose, so the
// tests prove the drill presentation replaces them rather than filling a gap.
const drillRow = (children, overrides = {}) => baseRow({
    mappings: [
        {
            handle: 'content_blocks',
            label: 'Content blocks',
            node: 'blocks',
            native: false,
            rawValue: '{…}',
            parsedValue: '{"new1":{"type":"text"}}',
            currentValue: '(#512)',
            changed: true,
            children,
            childrenType: 'blocks',
            ...overrides,
        },
    ],
});

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

    describe('status-pill explanation', () => {
        const defaultedRow = () => baseRow({
            mappings: [
                { handle: 'status', label: 'Status', node: 'status', native: false, rawValue: null, parsedValue: 'for_sale', currentValue: 'for_sale', changed: false, usedDefault: true },
            ],
        });

        /**
         * happy-dom registers no custom elements, which is exactly the Craft 4
         * case — so the Craft 5 specs are the ones that have to pretend the CP
         * registered its tooltip.
         */
        const withCraftTooltip = (fn) => {
            const registered = window.customElements.get;
            window.customElements.get = (tag) => (tag === 'craft-tooltip' ? class {} : registered.call(window.customElements, tag));

            try {
                fn();
            } finally {
                window.customElements.get = registered;
            }
        };

        it('hands the sentence to Craft\'s own tooltip', () => {
            // The CP registers <craft-tooltip> and owns the positioning,
            // flipping and theming; the plugin only says what it should read.
            withCraftTooltip(() => {
                const tooltip = mountDetail({ row: defaultedRow() }).find('craft-tooltip');

                expect(tooltip.exists()).toBe(true);
                expect(tooltip.attributes('text')).toContain('default value');
                expect(tooltip.find('.influx-detail-pill--default').exists()).toBe(true);
            });
        });

        it('makes the sentence the pill\'s accessible name, not a second tooltip', () => {
            // Craft's tooltip sets no role="tooltip" and no aria-describedby, so
            // without the label a reader who can't see it gets "use default" and
            // no way to reach the why. The native title would double up.
            withCraftTooltip(() => {
                const pill = mountDetail({ row: defaultedRow() }).find('.influx-detail-pill--default');

                expect(pill.attributes('aria-label')).toContain('default value');
                expect(pill.attributes('title')).toBeUndefined();
            });
        });

        it('falls back to a native title where the CP registers no tooltip', () => {
            // Craft 4 ships no such element: the wrapper is an inert span and the
            // sentence rides the button's own title instead.
            const w = mountDetail({ row: defaultedRow() });

            expect(w.find('craft-tooltip').exists()).toBe(false);
            expect(w.find('.influx-detail-pill--default').attributes('title')).toContain('default value');
        });
    });

    describe('drill row', () => {
        it('replaces both value cells with a count summary, a state label and a chevron', () => {
            const w = mountDetail({ row: drillRow([child('unchanged'), child('would-add', [{ changed: true }])]) });
            const row = w.find('.influx-detail-row');

            expect(row.find('.influx-detail-drill-summary').text()).toBe('2 blocks');
            expect(row.find('.influx-detail-drill-state').text()).toBe('1 change');
            expect(row.find('[data-icon="rightangle"]').exists()).toBe(true);
            // The nested blob and the element's current value are gone — the
            // summary is the row's value now.
            expect(row.findAll('.influx-detail-val')).toHaveLength(0);
        });

        it('names the count by children type', () => {
            const summary = (childrenType) => mountDetail({
                row: drillRow([child('unchanged'), child('unchanged')], { childrenType }),
            }).find('.influx-detail-drill-summary').text();

            expect(summary('entries')).toBe('2 entries');
            // A Table field's children are its rows, not elements.
            expect(summary('rows')).toBe('2 rows');
        });

        it('labels the worst state inside — error over missing node over change', () => {
            const label = (children) => mountDetail({ row: drillRow(children) }).find('.influx-detail-drill-state');

            expect(label([child('error', [{ error: 'Boom' }]), child('would-add')]).text()).toBe('2 errors');
            expect(label([child('would-add', [{ unaddressed: true }])]).text()).toBe('1 missing node');
            expect(label([child('would-add'), child('would-remove')]).text()).toBe('2 changes');
            expect(label([child('unchanged')]).text()).toBe('No changes');
        });

        it('washes the row with that same worst state', () => {
            const state = (children) => mountDetail({ row: drillRow(children) })
                .find('.influx-detail-row').attributes('data-drill-state');

            expect(state([child('error'), child('would-add', [{ unaddressed: true }])])).toBe('error');
            expect(state([child('would-add', [{ unaddressed: true }])])).toBe('warn');
            expect(state([child('would-add')])).toBe('changed');
            expect(state([child('unchanged')])).toBe(undefined);
        });

        it('never also fires the plain changed tint', () => {
            const row = mountDetail({ row: drillRow([child('would-add')]) }).find('.influx-detail-row');

            expect(row.attributes('data-changed')).toBe(undefined);
            expect(row.classes()).toContain('influx-detail-row--drill');
        });

        it('emits drill with the mapping on click and on Enter', async () => {
            const row = drillRow([child('would-add')]);
            const clicked = mountDetail({ row });
            const keyed = mountDetail({ row });

            await clicked.find('.influx-detail-row').trigger('click');
            await keyed.find('.influx-detail-row').trigger('keyup.enter');

            expect(clicked.emitted('drill')).toEqual([[row.mappings[0]]]);
            expect(keyed.emitted('drill')).toEqual([[row.mappings[0]]]);
        });

        it('lets a status pill be pressed without also drilling', async () => {
            // `.stop` stops propagation, not immediate propagation, so the
            // tooltip's own listener on the button still runs — only the row's
            // drill toggle is spared.
            const row = drillRow([child('would-add')], { unaddressed: true });
            const w = mountDetail({ row });

            await w.find('.influx-detail-pill--untouched').trigger('click');

            expect(w.emitted('drill')).toBe(undefined);
        });

        it('leaves a plain row alone — no chevron, no drill', async () => {
            const w = mountDetail();

            expect(w.find('[data-icon="rightangle"]').exists()).toBe(false);
            expect(w.find('.influx-detail-row').attributes('role')).toBe(undefined);

            await w.find('.influx-detail-row').trigger('click');
            expect(w.emitted('drill')).toBe(undefined);
        });
    });

    describe('drilled mode', () => {
        it('drops the Parsed / Raw switch — a child has no payload of its own', () => {
            expect(mountDetail().find('.influx-detail-toggle').exists()).toBe(true);
            expect(mountDetail({ drilled: true }).find('.influx-detail-toggle').exists()).toBe(false);
        });

        it('stays on the parsed table whatever the local view holds', async () => {
            const w = mountDetail({ drilled: true });
            w.vm.view = 'raw';
            await w.vm.$nextTick();

            expect(w.find('.influx-detail-body').exists()).toBe(true);
            expect(w.find('.influx-detail-raw').exists()).toBe(false);
        });

        it('never pills an ordinal before the header label', () => {
            expect(mountDetail({ drilled: true, fallbackLabel: '02' }).find('.influx-drill-index').exists()).toBe(false);
        });

        it('heads a child that stands for a real element with its chip', () => {
            const row = {
                action: 'unchanged',
                title: 'Werfkelder',
                element: { id: 512, title: 'Werfkelder', chipHtml: '<a class="chip" href="/cp/edit/512">Werfkelder</a>' },
                mappings: [],
            };
            const w = mountDetail({ row, drilled: true, fallbackLabel: '01' });

            expect(w.find('.influx-detail-chip').html()).toContain('/cp/edit/512');
            expect(w.find('.influx-detail-title').exists()).toBe(false);
        });

        it('heads a chipless child row by its own title — children carry no match value', () => {
            const row = { action: 'would-add', title: 'Afbeelding', element: null, mappings: [] };

            expect(mountDetail({ row, drilled: true, fallbackLabel: '02' }).find('.influx-detail-title').text()).toBe('Afbeelding');
        });

        it('falls back to the label the host hands down when there is neither chip nor title', () => {
            const row = { action: 'would-add', title: null, element: null, mappings: [] };

            expect(mountDetail({ row, drilled: true, fallbackLabel: '02' }).find('.influx-detail-title').text()).toBe('02');
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
