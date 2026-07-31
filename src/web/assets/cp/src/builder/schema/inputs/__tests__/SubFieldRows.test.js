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
