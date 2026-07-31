import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MatrixFields from '../MatrixFields.vue';
import SearchableSelect from '../../../SearchableSelect.vue';

/**
 * Locks in the blocks-channel writer contract: the card owns ONE block
 * type's slice of the mapping's whole `blocks` object. Child rows land
 * under `blocks[type].fields[handle]` — or `blocks[type].nativeFields[handle]`
 * for a row whose schema node declares `channel: 'nativeFields'` (the block's
 * native Title) — with the same node/default/useDefault vocabulary as the
 * parent rows; other types' slices, unknown keys on the card's own type
 * entry, and a child's unknown keys (per-type `options`, nested `fields`, …)
 * survive every rewrite untouched; emptied slices collapse away (row →
 * channel map → type entry).
 */

const node = {
    type: 'matrixFields',
    handle: 'blocks',
    label: 'Quote',
    blockType: 'quote',
    subFields: [
        { type: 'text', handle: 'title', label: 'Title', channel: 'nativeFields' },
        { type: 'text', handle: 'quote', label: 'Quote' },
        { type: 'text', handle: 'cite', label: 'Cite' },
    ],
};

const mountFields = (props = {}) => mount(MatrixFields, {
    props: {
        node,
        modelValue: {},
        nodeOptions: [
            { value: 'quotes.text', label: 'quotes.text' },
            { value: 'quotes.author', label: 'quotes.author' },
        ],
        ...props,
    },
    global: { mocks: { $t: (s) => s } },
});

// Rows render in node.subFields order, one source-node control and one
// default-value input each.
const rowIndex = (handle) => node.subFields.findIndex((sub) => sub.handle === handle);
const nodeSelect = (wrapper, handle) => wrapper.findAllComponents(SearchableSelect).at(rowIndex(handle));
const defaultInput = (wrapper, handle) => wrapper.findAll('input[type="text"]').at(rowIndex(handle));

describe('MatrixFields', () => {
    it('starts collapsed without saved rows, open with them', () => {
        const empty = mountFields();
        expect(empty.find('.influx-mapping-group').classes()).toContain('collapsed');

        const saved = mountFields({
            modelValue: { quote: { fields: { quote: { node: 'quotes.text' } } } },
        });
        expect(saved.find('.influx-mapping-group').classes()).not.toContain('collapsed');
    });

    it('renders each row as name label + code handle, like other sub-field rows', () => {
        const wrapper = mountFields();
        const quoteRow = wrapper.findAll('.sub-field-row label').at(rowIndex('quote'));

        expect(quoteRow.text()).toContain('Quote');
        expect(quoteRow.find('code.handle').text()).toBe('quote');
    });

    it('writes a picked node under blocks[type].fields[handle].node', () => {
        const wrapper = mountFields();
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { node: 'quotes.text' } } } }]);
    });

    it('leaves other block types\' slices untouched on every write', () => {
        const wrapper = mountFields({
            modelValue: { stat: { fields: { number: { node: 'stats.value' } } } },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            stat:  { fields: { number: { node: 'stats.value' } } },
            quote: { fields: { quote: { node: 'quotes.text' } } },
        }]);
    });

    it('preserves unknown keys on its own type\'s entry (futureFlag, …)', () => {
        const wrapper = mountFields({
            modelValue: { quote: { futureFlag: true } },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', 'quotes.text');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                futureFlag: true,
                fields: { quote: { node: 'quotes.text' } },
            },
        }]);
    });

    it('drops a child handle when its last slot empties', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text' }, cite: { node: 'quotes.author' } } },
            },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { cite: { node: 'quotes.author' } } } }]);
    });

    it('collapses the type out of blocks when its last child clears', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text' } } },
                stat:  { fields: { number: { node: 'stats.value' } } },
            },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ stat: { fields: { number: { node: 'stats.value' } } } }]);

        // …and to a bare {} when no other type remains — MappingRow's
        // writeMapping() then prunes the empty `blocks` off the mapping.
        const last = mountFields({
            modelValue: { quote: { fields: { quote: { node: 'quotes.text' } } } },
        });
        nodeSelect(last, 'quote').vm.$emit('update:modelValue', '');

        expect(last.emitted('update:modelValue').at(-1)).toEqual([{}]);
    });

    it('keeps a type entry alive on unknown keys alone when fields empties', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: {
                    fields: { quote: { node: 'quotes.text' } },
                    futureFlag: true,
                },
            },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { futureFlag: true } }]);
    });

    it('round-trips the __default__ sentinel to useDefault, never the wire node', () => {
        const wrapper = mountFields({
            modelValue: { quote: { fields: { quote: { node: 'quotes.text' } } } },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', '__default__');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { useDefault: true } } } }]);

        // And the saved flag renders back as the sentinel.
        const hydrated = mountFields({
            modelValue: { quote: { fields: { quote: { useDefault: true } } } },
        });
        expect(nodeSelect(hydrated, 'quote').props('modelValue')).toBe('__default__');
    });

    it('keeps a child\'s unknown keys intact across a write round-trip', async () => {
        const saved = {
            quote: {
                fields: {
                    quote: {
                        node: 'quotes.text',
                        options: { format: 'raw' },
                        fields: { nested: { node: 'quotes.meta.id' } },
                    },
                },
            },
        };
        const wrapper = mountFields({ modelValue: saved });

        // Rewriting the row's default must not disturb options / fields.
        await defaultInput(wrapper, 'quote').setValue('Fallback');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                fields: {
                    quote: {
                        node: 'quotes.text',
                        default: 'Fallback',
                        options: { format: 'raw' },
                        fields: { nested: { node: 'quotes.meta.id' } },
                    },
                },
            },
        }]);
    });

    it('keeps a row alive on unknown keys alone when node/default clear out', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: { fields: { quote: { node: 'quotes.text', options: { format: 'raw' } } } },
            },
        });
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { options: { format: 'raw' } } } } }]);
    });

    it('flags saved nodes missing from the fetched sample — never without a sample', () => {
        const saved = { quote: { fields: { quote: { node: 'gone.node' } } } };

        // No sample fetched (null) → can't know → nothing is missing.
        const unfetched = mountFields({ modelValue: saved, discoveredNodes: null });
        expect(unfetched.find('.influx-missing-badge').exists()).toBe(false);

        const fetched = mountFields({
            modelValue: saved,
            discoveredNodes: [{ value: 'quotes.text', label: 'quotes.text' }],
        });
        expect(fetched.find('.influx-missing-badge').exists()).toBe(true);
        expect(fetched.find('.pill-missing').text()).toContain('1');
    });

    it('renders an empty-state hint — not the column headings — for a fieldless block type', () => {
        const wrapper = mountFields({ node: { ...node, subFields: [] } });

        expect(wrapper.text()).toContain('This block type has no mappable sub-fields.');
        expect(wrapper.find('.influx-mapping-headings').exists()).toBe(false);
        expect(wrapper.find('.sub-field-row').exists()).toBe(false);
        expect(wrapper.find('.pill-count').text()).toBe('0');
    });

    // -- native channel -------------------------------------------------------

    it('writes a channel: nativeFields row under blocks[type].nativeFields', () => {
        const wrapper = mountFields();
        nodeSelect(wrapper, 'title').vm.$emit('update:modelValue', 'quotes.author');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { nativeFields: { title: { node: 'quotes.author' } } } }]);
    });

    it('renders both channels as one table and keeps them independent', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: {
                    fields:       { quote: { node: 'quotes.text' } },
                    nativeFields: { title: { node: 'quotes.author' } },
                },
            },
        });

        // Both saved rows hydrate their own control…
        expect(nodeSelect(wrapper, 'title').props('modelValue')).toBe('quotes.author');
        expect(nodeSelect(wrapper, 'quote').props('modelValue')).toBe('quotes.text');

        // …and editing one leaves the other channel exactly as it was.
        nodeSelect(wrapper, 'quote').vm.$emit('update:modelValue', 'quotes.meta.body');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                nativeFields: { title: { node: 'quotes.author' } },
                fields:       { quote: { node: 'quotes.meta.body' } },
            },
        }]);
    });

    it('collapses an emptied nativeFields off the type entry', () => {
        const wrapper = mountFields({
            modelValue: {
                quote: {
                    fields:       { quote: { node: 'quotes.text' } },
                    nativeFields: { title: { node: 'quotes.author' } },
                },
            },
        });
        nodeSelect(wrapper, 'title').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1))
            .toEqual([{ quote: { fields: { quote: { node: 'quotes.text' } } } }]);
    });

    it('collapses the type out of blocks when the native row was its last', () => {
        const wrapper = mountFields({
            modelValue: { quote: { nativeFields: { title: { node: 'quotes.author' } } } },
        });
        nodeSelect(wrapper, 'title').vm.$emit('update:modelValue', '');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{}]);
    });

    it('round-trips a stale saved native handle back into nativeFields', () => {
        // `legacy` is no longer among the type's subFields (the block type
        // dropped it), so only where it was SAVED says which channel it
        // belongs to — it must not migrate into `fields` on the next write.
        const wrapper = mountFields({
            modelValue: {
                quote: { nativeFields: { legacy: { node: 'quotes.legacy' } } },
            },
        });
        nodeSelect(wrapper, 'title').vm.$emit('update:modelValue', 'quotes.author');

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([{
            quote: {
                nativeFields: {
                    legacy: { node: 'quotes.legacy' },
                    title:  { node: 'quotes.author' },
                },
            },
        }]);
    });
});
