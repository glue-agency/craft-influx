import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SubFieldRows from '../SubFieldRows.vue';
import MappingGroupCard from '../../../../components/MappingGroupCard.vue';

/**
 * The card header's "Clear" affordance — a tester's shortcut for wiping one
 * group's rows without walking every select back to "— no mapping —".
 *
 * The regression this pins is the collision with MappingGroupCard's header,
 * which is itself the collapse toggle on click AND on keydown.enter/.space
 * (both with .prevent). Without .stop on both listeners a Clear press also
 * collapses the card, and the header's .prevent kills the button's native
 * Enter → click before it fires at all.
 */

const node = {
    type: 'elementSubFields',
    handle: 'nativeFields',
    label: 'Asset sub-fields',
    subFields: [
        { type: 'text', handle: 'alt', label: 'Alt text' },
        { type: 'text', handle: 'title', label: 'Title' },
    ],
};

const mountRows = (props = {}) => mount(SubFieldRows, {
    props: {
        node,
        rows: { alt: { node: 'images.0.alt' } },
        nodeOptions: [{ value: 'images.0.alt', label: 'images.0.alt' }],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

const clearBtn = (wrapper) => wrapper.find('.clear-rows');
const isCollapsed = (wrapper) => wrapper.find('.influx-mapping-group').classes().includes('collapsed');
const toggles = (wrapper) => wrapper.findComponent(MappingGroupCard).emitted('toggle');

describe('SubFieldRows clear', () => {
    it('shows the button only for an editable card with saved rows', () => {
        expect(clearBtn(mountRows()).exists()).toBe(true);
        expect(clearBtn(mountRows({ rows: {} })).exists()).toBe(false);
        expect(clearBtn(mountRows({ readOnly: true })).exists()).toBe(false);
    });

    it('emits an empty rows map — the consumer prunes from there', async () => {
        const wrapper = mountRows({
            rows: { alt: { node: 'images.0.alt' }, title: { default: 'Untitled' } },
        });
        await clearBtn(wrapper).trigger('click');

        expect(wrapper.emitted('update:rows').at(-1)).toEqual([{}]);
    });

    it('does not collapse the card it sits in', async () => {
        // Saved rows start the card open — a Clear press must leave it open.
        const wrapper = mountRows();
        expect(isCollapsed(wrapper)).toBe(false);

        await clearBtn(wrapper).trigger('click');

        expect(isCollapsed(wrapper)).toBe(false);
        expect(toggles(wrapper)).toBeUndefined();
    });

    it('does not collapse the card on a keyboard press either', async () => {
        // jsdom won't synthesise the Enter → click itself, so what's asserted
        // is the half that breaks in the browser: the keydown never reaches
        // the header, so neither its toggle nor its .prevent runs.
        const wrapper = mountRows();
        await clearBtn(wrapper).trigger('keydown.enter');
        await clearBtn(wrapper).trigger('keydown.space');

        expect(isCollapsed(wrapper)).toBe(false);
        expect(toggles(wrapper)).toBeUndefined();
    });

    it('still lets the header itself collapse the card', async () => {
        // Guards the two specs above from passing vacuously on a card that
        // stopped toggling altogether.
        const wrapper = mountRows();
        await wrapper.find('.influx-mapping-group-header').trigger('click');

        expect(isCollapsed(wrapper)).toBe(true);
        expect(toggles(wrapper)).toEqual([[false]]);
    });
});

/**
 * A row's default-value editor follows the sub-field's own type, the way the
 * top-level mapping row's already did. A relation sub-field rendering a text box
 * — Belkin's "Campus" case — was the whole of GT-107's fifth remark: a
 * reference can be picked, not retyped.
 */
describe('SubFieldRows default editors', () => {
    const campus = { type: 'element', handle: 'campus', label: 'Campus', elementType: 'craft\\elements\\Entry' };

    const mountTyped = (subFields, props = {}) => mount(SubFieldRows, {
        props: {
            node: { ...node, subFields },
            rows: { campus: { default: '12' } },
            nodeOptions: [],
            ...props,
        },
        global: { mocks: { $t: (s) => s }, stubs: { ElementPicker: true } },
    });

    const picker = (wrapper) => wrapper.findComponent({ name: 'ElementPicker' });

    it('gives a relation sub-field the element picker its own field would', () => {
        const wrapper = mountTyped([campus]);

        expect(picker(wrapper).props('elementType')).toBe('craft\\elements\\Entry');
        expect(picker(wrapper).props('modelValue')).toBe('12');
        // The SUB-field's handle, not the parent's: the server looks a handle up
        // globally, so this is what shapes the picker after the right field.
        expect(picker(wrapper).props('fieldHandle')).toBe('campus');
        expect(wrapper.find('input[type="text"]').exists()).toBe(false);
    });

    it('stores what the picker emits, and clears the row when it empties', () => {
        const wrapper = mountTyped([campus]);

        picker(wrapper).vm.$emit('update:modelValue', '34');
        expect(wrapper.emitted('update:rows').at(-1)).toEqual([{ campus: { default: '34' } }]);

        picker(wrapper).vm.$emit('update:modelValue', null);
        expect(wrapper.emitted('update:rows').at(-1)).toEqual([{}]);
    });

    it('holds the picker back until the card is open', async () => {
        // Every card of every relation row renders at once, and each picker
        // fetches its markup from the server — so a collapsed card asks for
        // nothing.
        const wrapper = mountTyped([campus], { rows: {} });
        expect(isCollapsed(wrapper)).toBe(true);
        expect(picker(wrapper).exists()).toBe(false);

        await wrapper.find('.influx-mapping-group-header').trigger('click');

        expect(picker(wrapper).exists()).toBe(true);
    });

    /**
     * A nested field is configured the way the same field is at the top level: its
     * own extras, behind its own disclosure, writing its own `options`. All of it is
     * honoured at sync time, because a sub-row IS a whole mapping the applier
     * descends into — before this, a Matrix block's Assets child was stuck matching
     * by asset id whatever the feed carried, with no control in sight to change it.
     */
    it('gives a row its field’s own extras, behind its own chevron', async () => {
        const wrapper = mountTyped([
            { type: 'element', handle: 'photo', label: 'Photo', extra: [
                { type: 'select', handle: 'mode', label: 'Match by', default: 'id', options: [
                    { value: 'id', label: 'Asset ID' },
                    { value: 'url', label: 'URL' },
                ] },
            ] },
            { type: 'text', handle: 'blurb', label: 'Blurb' },
        ]);

        // Only the row that declares extras gets a toggle.
        expect(wrapper.findAll('.sub-field-row .extras-chevron')).toHaveLength(1);
        expect(wrapper.findComponent({ name: 'MappingExtras' }).exists()).toBe(false);

        await wrapper.find('.sub-field-row .extras-chevron').trigger('click');

        expect(wrapper.findComponent({ name: 'MappingExtras' }).props('nodes'))
            .toEqual([expect.objectContaining({ handle: 'mode' })]);
    });

    it('toggles from anywhere in the field cell, not just the chevron', async () => {
        // The whole label is the target, the way a parent row's meta cell is.
        const wrapper = mountTyped([
            { type: 'element', handle: 'photo', label: 'Photo', extra: [
                { type: 'lightswitch', handle: 'upload', label: 'Download missing' },
            ] },
        ]);
        const label = wrapper.find('.sub-field-row > label.is-toggleable');

        await label.trigger('click');
        expect(wrapper.findComponent({ name: 'MappingExtras' }).exists()).toBe(true);

        await label.trigger('click');
        expect(wrapper.findComponent({ name: 'MappingExtras' }).exists()).toBe(false);
    });

    it('writes a row’s extras into that row’s own options', async () => {
        const wrapper = mountTyped([
            { type: 'element', handle: 'photo', label: 'Photo', extra: [
                { type: 'lightswitch', handle: 'upload', label: 'Download missing' },
            ] },
        ], { rows: { photo: { node: 'images.0.url' } } });

        await wrapper.find('.sub-field-row .extras-chevron').trigger('click');
        await wrapper.find('.sub-field-extras input[type="checkbox"]').setValue(true);

        // The row's node rides along untouched — its extras configure it, they don't
        // replace it.
        expect(wrapper.emitted('update:rows').at(-1)).toEqual([{
            photo: { node: 'images.0.url', options: { upload: true } },
        }]);
    });

    it('starts a row open when its extras are already configured', () => {
        // Otherwise a live `mode: url` sits hidden behind a chevron nobody thought
        // to click — which is how it was invisible in the first place.
        const wrapper = mountTyped([
            { type: 'element', handle: 'photo', label: 'Photo', extra: [
                { type: 'lightswitch', handle: 'upload', label: 'Download missing' },
            ] },
        ], { rows: { photo: { node: 'images.0.url', options: { upload: true } } } });

        expect(wrapper.findComponent({ name: 'MappingExtras' }).exists()).toBe(true);
    });

    it('reads an unpicked default as an empty cell, like a top-level row', () => {
        // A card row used to label its empty state "—" where the row above it showed
        // nothing at all. Both go through the same sentinel now, so both read empty
        // — a nested row is configured the way its field is at the top level, right
        // down to what "not set" looks like.
        const wrapper = mountTyped([{
            type:            'select',
            handle:          'size',
            label:           'Size',
            options:         [{ value: 'l', label: 'Large' }],
            sentinelOptions: [{ value: '', label: '— no default —' }],
        }], { rows: { size: { node: 'items.0.size' } } });
        const trigger = wrapper.findComponent({ name: 'SelectField' }).find('.value');

        expect(trigger.text()).toBe('');
        expect(trigger.classes()).toContain('placeholder');
    });

    it('renders neither control for a row whose value comes from its extras', async () => {
        // A nested Table or Link declares no cells at the top level either.
        // Rendering a node select and a text box for one gave the operator two
        // controls writing into slots no sync reads — while its real configuration
        // sat behind the chevron beside them.
        const wrapper = mountTyped([
            { type: 'text', handle: 'table', label: 'Tabel', cells: { source: false, default: false }, extra: [
                { type: 'lightswitch', handle: 'flag', label: 'A flag' },
            ] },
            { type: 'text', handle: 'blurb', label: 'Blurb' },
        ], { rows: {} });

        const rows = wrapper.findAll('.sub-field-row');
        expect(rows[0].findComponent({ name: 'SearchableSelect' }).exists()).toBe(false);
        expect(rows[0].find('input[type="text"]').exists()).toBe(false);

        // The row still expands to its own extras — that's where its value lives.
        await rows[0].find('.extras-chevron').trigger('click');
        expect(wrapper.findComponent({ name: 'MappingExtras' }).exists()).toBe(true);

        // And the ordinary row beside it is untouched.
        expect(rows[1].findComponent({ name: 'SearchableSelect' }).exists()).toBe(true);
        expect(rows[1].find('input[type="text"]').exists()).toBe(true);
    });

    it('keeps the node select for a row that only lacks a default', () => {
        // A nested Table Maker: one node holds the whole table, so the select is
        // the only cell it wants — and the only one it must not lose.
        const wrapper = mountTyped([
            { type: 'text', handle: 'table', label: 'Tabel', cells: { default: false }, extra: [
                { type: 'note', text: 'Ship a columns/values object.' },
            ] },
        ], { rows: {} });
        const row = wrapper.find('.sub-field-row');

        expect(row.findComponent({ name: 'SearchableSelect' }).exists()).toBe(true);
        expect(row.find('input[type="text"]').exists()).toBe(false);
    });

    it('leaves the other editors as they were', () => {
        const wrapper = mountTyped([
            { type: 'select', handle: 'size', label: 'Size', options: [{ value: '', label: '—' }, { value: 'l', label: 'Large' }] },
            { type: 'text', handle: 'blurb', label: 'Blurb' },
        ]);

        expect(wrapper.findComponent({ name: 'SelectField' }).exists()).toBe(true);
        expect(wrapper.find('input[type="text"]').exists()).toBe(true);
    });
});
