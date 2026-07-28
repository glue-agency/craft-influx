import { afterEach, describe, expect, it } from 'vitest';
import generated from '../vocabulary.generated.json';
import { actionColorMap, counterDefs, installVocabulary } from '../vocabulary.js';
import { actionColor } from '../actionColors.js';

/**
 * The JS half of the vocabulary contract: the apps install the server's payload
 * at boot and everything else reads it back. Nothing here re-encodes a palette —
 * the generated default is pinned against the enums by the PHP-side
 * VocabularyTest, so these only cover the plumbing.
 */
describe('vocabulary', () => {
    afterEach(() => {
        // Restore the generated default for whatever runs next in this file.
        installVocabulary(null);
    });

    it('boots from the generated default', () => {
        expect(actionColorMap()).toEqual(generated.actionColors);
        expect(counterDefs()).toEqual(generated.counters);
    });

    it('covers every committed action and dry-run label out of the box', () => {
        // Sanity check on the generated copy: the four latent sweep dry-run
        // labels are the ones a hand-maintained map kept missing.
        ['created', 'disabled-for-site', 'would-create', 'would-disable', 'would-delete-for-site']
            .forEach((action) => expect(actionColor(action)).not.toBe(undefined));

        expect(actionColor('would-delete')).toBe('expired');
    });

    it('adopts a shipped payload', () => {
        installVocabulary({
            actionColors: { created: 'expired' },
            counters: [{ key: 'itemsSeen', action: null, label: 'gezien', tone: null }],
        });

        expect(actionColor('created')).toBe('expired');
        expect(counterDefs()).toHaveLength(1);
        expect(counterDefs()[0].label).toBe('gezien');
    });

    it('keeps the default for anything a partial payload omits', () => {
        installVocabulary({ counters: [] });

        expect(counterDefs()).toEqual([]);
        expect(actionColorMap()).toEqual(generated.actionColors);
    });

    it('falls back to neutral for an action outside the map', () => {
        expect(actionColor('would-teleport')).toBe('pending');
        expect(actionColor(undefined)).toBe('pending');
    });
});
