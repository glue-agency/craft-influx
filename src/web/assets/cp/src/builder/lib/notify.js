import { t } from '../../lib/installT.js';

/**
 * CP-toast + error-unpacking helpers for the store's actions. Centralizes
 * the `window.Craft?.cp` guards (absent in tests / isolated builds) and the
 * ApiError shape (`e.message` / `e.errors`) so call sites don't re-branch on
 * either.
 */

export { t };

/** Show a red CP toast, when the CP chrome is available. */
export function notifyError(message) {
    window.Craft?.cp?.displayError?.(message);
}

/** Show a green CP toast, when the CP chrome is available. */
export function notifyNotice(message) {
    window.Craft?.cp?.displayNotice?.(message);
}

/** The human-readable message out of a thrown ApiError, or the fallback. */
export function errorText(e, fallback) {
    return e?.message || fallback;
}
