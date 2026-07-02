import { describe, expect, it } from 'vitest';
import { requestErrorMessage } from '../requestError.js';

/**
 * The axios-shaped error unwrap shared by DebugApp's inspect and LogItem's
 * detail fetch: response message → error message → caller fallback.
 */
describe('requestErrorMessage', () => {
    it('prefers the JSON message the controller responded with', () => {
        const err = { response: { data: { message: 'Bad feed' } }, message: 'Request failed with status code 400' };

        expect(requestErrorMessage(err, 'fallback')).toBe('Bad feed');
    });

    it('falls back to the transport error message', () => {
        expect(requestErrorMessage(new Error('Network Error'), 'fallback')).toBe('Network Error');
    });

    it('falls back to the caller fallback when neither exists', () => {
        expect(requestErrorMessage({}, 'Inspection failed.')).toBe('Inspection failed.');
        expect(requestErrorMessage(undefined, 'Inspection failed.')).toBe('Inspection failed.');
    });
});
