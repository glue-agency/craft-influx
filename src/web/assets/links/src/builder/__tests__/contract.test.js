import { describe, expect, it } from 'vitest';
import fixture from '../../../tests/fixtures/link-payload.json';

/**
 * The JS half of the wire-contract test. PHP is the authority
 * (Link::toBuilderArray() — asserted against the same fixture by
 * LinkBuilderPayloadTest); this side pins the key set and value shapes
 * the store and types.js LinkPayload typedef assume. Drift on either
 * side fails one of the two suites.
 */

const LINK_PAYLOAD_KEYS = [
    'id', 'uid', 'handle', 'name', 'elementType', 'elementCriteria',
    'endpoint', 'itemEndpoint', 'siteEndpoints', 'offset', 'processing',
    'rootNode', 'paginatorNode', 'mappings', 'match', 'auth', 'backup',
].sort();

describe('Link wire contract', () => {
    it('carries exactly the keys types.js documents', () => {
        expect(Object.keys(fixture).sort()).toEqual(LINK_PAYLOAD_KEYS);
    });

    it('shapes the values the way the store assumes', () => {
        expect(typeof fixture.handle).toBe('string');
        expect(typeof fixture.elementType).toBe('string');
        expect(Array.isArray(fixture.processing)).toBe(true);
        expect(typeof fixture.backup).toBe('boolean');
        // mappings/match are keyed maps; the empty-collection cast quirk
        // (PHP `(object)[]` → `{}`, fixture `[]`) only affects empties.
        expect(typeof fixture.mappings).toBe('object');
        expect(fixture.mappings.importId.node).toBe('id');
        expect(fixture.match.attribute).toBe('importId');
    });
});
