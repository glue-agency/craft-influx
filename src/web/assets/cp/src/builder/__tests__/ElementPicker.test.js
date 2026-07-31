import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

vi.mock('../api.js', () => ({
    renderElementSelect: vi.fn(),
}));

import * as api from '../api.js';
import ElementPicker from '../ElementPicker.vue';

/**
 * What the picker sends the server, and what it emits back to the row.
 *
 * The picker's SHAPE is the server's call — it answers with `single`, derived
 * from the mapped field's maxRelations. This side only has to honour it: a
 * one-element picker emits the bare id string every default saved so far is
 * stored as, a multi one emits the list of picked ids, and neither ever emits
 * an empty list (FieldMapping::usesDefault() would read that as a default that
 * IS set).
 *
 * Craft's BaseElementSelectInput is faked down to the three members the
 * component touches: the event hookup, the selected-id read, and destroy().
 */

class FakeSelectInput {
    constructor(settings) {
        this.settings = settings;
        this.handlers = {};
        this.ids = [];
        FakeSelectInput.last = this;
    }

    on(event, handler) {
        (this.handlers[event] ||= []).push(handler);
    }

    // Stand-in for a user choosing / removing elements in the modal.
    pick(ids) {
        this.ids = ids;
        for (const handler of this.handlers.selectElements || []) handler();
    }

    getSelectedElementIds() {
        return this.ids;
    }

    destroy() {}
}

const payload = (single) => ({ html: '<div class="elementselect"></div>', jsSettings: { single } });

const mountPicker = async (props = {}) => {
    const wrapper = mount(ElementPicker, {
        props: { elementType: 'craft\\elements\\Entry', ...props },
    });
    await flushPromises();

    return wrapper;
};

const emitted = (wrapper) => wrapper.emitted('update:modelValue').at(-1)[0];

beforeEach(() => {
    vi.clearAllMocks();
    window.Craft.BaseElementSelectInput = FakeSelectInput;
    api.renderElementSelect.mockResolvedValue(payload(true));
});

describe('ElementPicker request', () => {
    it('names the field it picks for, so the server can shape the picker', async () => {
        await mountPicker({ modelValue: null, fieldHandle: 'relatedArticles' });

        expect(api.renderElementSelect).toHaveBeenCalledWith('craft\\elements\\Entry', [], 'relatedArticles');
    });

    it('sends the whole list a multi-relation default holds', async () => {
        await mountPicker({ modelValue: ['12', '34'], fieldHandle: 'relatedArticles' });

        expect(api.renderElementSelect).toHaveBeenCalledWith('craft\\elements\\Entry', ['12', '34'], 'relatedArticles');
    });

    it('sends a bare id as the one-element list it is', async () => {
        await mountPicker({ modelValue: 12 });

        expect(api.renderElementSelect).toHaveBeenCalledWith('craft\\elements\\Entry', ['12'], null);
    });

    it('re-renders when the field it picks for changes', async () => {
        const wrapper = await mountPicker({ modelValue: null, fieldHandle: 'relatedArticles' });

        await wrapper.setProps({ fieldHandle: 'relatedPeople' });
        await flushPromises();

        expect(api.renderElementSelect).toHaveBeenLastCalledWith('craft\\elements\\Entry', [], 'relatedPeople');
    });
});

describe('ElementPicker emits', () => {
    it('emits a bare id for a single-element picker', async () => {
        const wrapper = await mountPicker({ modelValue: null });

        FakeSelectInput.last.pick([12]);

        expect(emitted(wrapper)).toBe('12');
    });

    it('emits the list for a multi-element picker', async () => {
        api.renderElementSelect.mockResolvedValue(payload(false));
        const wrapper = await mountPicker({ modelValue: null, fieldHandle: 'relatedArticles' });

        FakeSelectInput.last.pick([12, 34]);

        expect(emitted(wrapper)).toEqual(['12', '34']);
    });

    it('emits null rather than an empty list when everything is removed', async () => {
        api.renderElementSelect.mockResolvedValue(payload(false));
        const wrapper = await mountPicker({ modelValue: ['12'], fieldHandle: 'relatedArticles' });

        FakeSelectInput.last.pick([]);

        expect(emitted(wrapper)).toBe(null);
    });

    it('stays quiet when the selection did not actually change', async () => {
        const wrapper = await mountPicker({ modelValue: '12' });

        FakeSelectInput.last.pick([12]);

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});
