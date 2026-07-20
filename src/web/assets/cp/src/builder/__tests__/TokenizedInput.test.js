import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import TokenizedInput from '../TokenizedInput.vue';
import TokenChip from '../components/TokenChip.vue';

/**
 * Component spec for TokenizedInput's select-all + clipboard layer. The
 * segment math is pinned in useTokenSegments.test.js and the picker state
 * machine in useTokenPicker.test.js — this file covers the DOM glue: a
 * Cmd/Ctrl+A that spans every segment, copy/cut of the FULL serialized
 * value (native selection can't cross the separate inputs, so chips were
 * always lost), and paste re-parsing so known tokens chip-ify.
 */

const GROUPS = [
    { label: 'Site', kind: 'site', data: [{ name: '@syncUrl', hint: 'Base URL' }] },
    { label: 'Environment', kind: 'env', data: [{ name: '$API_BASE', hint: '' }] },
];

const VALUE = 'https://api.test/@syncUrl/items';

const mountInput = (props = {}, mountOptions = {}) => mount(TokenizedInput, {
    props: { modelValue: VALUE, tokenGroups: GROUPS, ...props },
    global: { mocks: { $t: (s) => s } },
    ...mountOptions,
});

const textInputs = (wrapper) => wrapper.findAll('input.influx-tokenized-text');
const chips = (wrapper) => wrapper.findAllComponents(TokenChip);
const lastEmitted = (wrapper) => wrapper.emitted('update:modelValue')?.at(-1);

const selectAll = async (wrapper) => {
    await textInputs(wrapper)[0].trigger('keydown', { key: 'a', metaKey: true });
};

// happy-dom's ClipboardEvent carries no clipboardData, so fake the
// DataTransfer on a plain (bubbling, cancelable) Event — Vue's listeners
// only care about the event name.
const clipboardEvent = (type, text = '') => {
    const event = new Event(type, { bubbles: true, cancelable: true });
    event.clipboardData = { getData: () => text, setData: vi.fn() };
    return event;
};

const dispatch = async (wrapper, index, event) => {
    textInputs(wrapper)[index].element.dispatchEvent(event);
    await nextTick();
    return event;
};

describe('rendering', () => {
    it('parses the model value into text segments around kind-colored chips', () => {
        const wrapper = mountInput();
        expect(textInputs(wrapper)).toHaveLength(2);
        expect(textInputs(wrapper)[0].element.value).toBe('https://api.test/');
        expect(textInputs(wrapper)[1].element.value).toBe('/items');
        expect(chips(wrapper)).toHaveLength(1);
        expect(chips(wrapper)[0].props('name')).toBe('@syncUrl');
        expect(chips(wrapper)[0].props('kind')).toBe('site');
    });
});

describe('select-all state', () => {
    it.each(['metaKey', 'ctrlKey'])('%s+A flips the component to all-selected', async (modifier) => {
        const wrapper = mountInput();
        await textInputs(wrapper)[0].trigger('keydown', { key: 'a', [modifier]: true });
        expect(wrapper.classes()).toContain('all-selected');
    });

    it('leaves on Escape without touching the value', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('keydown', { key: 'Escape' });
        expect(wrapper.classes()).not.toContain('all-selected');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('leaves on a caret move (arrow keys)', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it('leaves when a segment takes focus', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[1].trigger('focus');
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it('leaves on a click anywhere in the component', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('click');
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it('stays put for modified keys so clipboard combos reach copy/cut/paste', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('keydown', { key: 'c', metaKey: true });
        expect(wrapper.classes()).toContain('all-selected');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});

describe('copy / cut', () => {
    it('copies the full serialized value while all-selected', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        const event = await dispatch(wrapper, 0, clipboardEvent('copy'));
        expect(event.defaultPrevented).toBe(true);
        expect(event.clipboardData.setData).toHaveBeenCalledWith('text/plain', VALUE);
        // Copy keeps the selection (and the value) intact.
        expect(wrapper.classes()).toContain('all-selected');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('leaves a plain per-segment copy alone when not all-selected', async () => {
        const wrapper = mountInput();

        const event = await dispatch(wrapper, 0, clipboardEvent('copy'));
        expect(event.defaultPrevented).toBe(false);
        expect(event.clipboardData.setData).not.toHaveBeenCalled();
    });

    it('cut copies the full value, then clears it', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        const event = await dispatch(wrapper, 0, clipboardEvent('cut'));
        expect(event.clipboardData.setData).toHaveBeenCalledWith('text/plain', VALUE);
        expect(lastEmitted(wrapper)).toEqual(['']);
        expect(chips(wrapper)).toHaveLength(0);
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it('leaves cut alone when not all-selected', async () => {
        const wrapper = mountInput();

        const event = await dispatch(wrapper, 0, clipboardEvent('cut'));
        expect(event.defaultPrevented).toBe(false);
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });
});

describe('paste', () => {
    it('chip-ifies known tokens in the pasted text', async () => {
        const wrapper = mountInput({ modelValue: '' });

        await dispatch(wrapper, 0, clipboardEvent('paste', VALUE));
        expect(lastEmitted(wrapper)).toEqual([VALUE]);
        expect(chips(wrapper)).toHaveLength(1);
        expect(chips(wrapper)[0].props('name')).toBe('@syncUrl');
    });

    it('keeps unknown @handles as plain text', async () => {
        const wrapper = mountInput({ modelValue: '' });

        await dispatch(wrapper, 0, clipboardEvent('paste', 'https://x.test/@nope'));
        expect(lastEmitted(wrapper)).toEqual(['https://x.test/@nope']);
        expect(chips(wrapper)).toHaveLength(0);
    });

    it('replaces a partial in-segment selection and restores the caret after the paste', async () => {
        const wrapper = mountInput({ modelValue: 'https://old.test/items' }, { attachTo: document.body });
        const input = textInputs(wrapper)[0];
        input.element.setSelectionRange(8, 16); // selects 'old.test'

        await dispatch(wrapper, 0, clipboardEvent('paste', 'api.test'));
        expect(lastEmitted(wrapper)).toEqual(['https://api.test/items']);

        await nextTick(); // focusSegment lands the caret a tick later
        // The re-parse rebuilds segment ids, so the keyed <input> element
        // was swapped out — re-query it.
        const after = textInputs(wrapper)[0];
        expect(document.activeElement).toBe(after.element);
        expect(after.element.selectionStart).toBe('https://api.test'.length);

        wrapper.unmount();
    });

    it('replaces the whole value while all-selected', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await dispatch(wrapper, 0, clipboardEvent('paste', '$API_BASE/feed'));
        expect(lastEmitted(wrapper)).toEqual(['$API_BASE/feed']);
        expect(chips(wrapper)).toHaveLength(1);
        expect(chips(wrapper)[0].props('name')).toBe('$API_BASE');
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it('strips newlines from the pasted text', async () => {
        const wrapper = mountInput({ modelValue: '' });

        await dispatch(wrapper, 0, clipboardEvent('paste', 'line1\r\nline2\nline3'));
        expect(lastEmitted(wrapper)).toEqual(['line1line2line3']);
    });
});

describe('typing while all-selected', () => {
    it('a printable character replaces the whole value', async () => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('keydown', { key: 'x' });
        expect(lastEmitted(wrapper)).toEqual(['x']);
        expect(chips(wrapper)).toHaveLength(0);
        expect(textInputs(wrapper)).toHaveLength(1);
        expect(textInputs(wrapper)[0].element.value).toBe('x');
        expect(wrapper.classes()).not.toContain('all-selected');
    });

    it.each(['Backspace', 'Delete'])('%s clears the value', async (key) => {
        const wrapper = mountInput();
        await selectAll(wrapper);

        await textInputs(wrapper)[0].trigger('keydown', { key });
        expect(lastEmitted(wrapper)).toEqual(['']);
        expect(chips(wrapper)).toHaveLength(0);
    });
});

describe('boundary chip-eat (unchanged by the select-all layer)', () => {
    it('Backspace at a segment start still removes the preceding chip', async () => {
        const wrapper = mountInput();
        const tail = textInputs(wrapper)[1];
        tail.element.setSelectionRange(0, 0);

        await tail.trigger('keydown', { key: 'Backspace' });
        expect(lastEmitted(wrapper)).toEqual(['https://api.test//items']);
        expect(chips(wrapper)).toHaveLength(0);
    });
});
