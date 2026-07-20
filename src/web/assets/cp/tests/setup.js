/**
 * Global test setup: the minimal Craft CP surface the SPA touches, so
 * store/api/component code runs outside the control panel. Keep this in
 * sync with what the code actually calls — a missing stub failing loudly
 * beats a sprawling fake CP.
 */

const t = (category, message, params = {}) =>
    String(message).replace(/\{(\w+)\}/g, (match, key) => (key in params ? String(params[key]) : match));

const craft = {
    t,
    getCpUrl: (path = '') => `/admin/${path}`,
    getActionUrl: (action) => `/index.php?p=admin/actions/${action}`,
    // Resolves to an empty payload by default; tests that exercise the debug
    // inspect / log poll endpoints override this with their own response.
    sendActionRequest: () => Promise.resolve({ data: {} }),
    cp: {
        displayNotice: () => {},
        displayError: () => {},
    },
};

globalThis.Craft = craft;
if (typeof window !== 'undefined') {
    window.Craft = craft;
}
