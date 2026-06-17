/**
 * Sync action → Craft status colour (live = green, pending = grey, expired =
 * red). Covers both the dry-run ("would-…") labels the debug inspector shows
 * and the committed labels the run log shows. Shared by DebugItem + LogItem so
 * the colour of an action is defined once.
 */
export const ACTION_COLORS = {
    'would-create':     'live',
    'would-update':     'live',
    'would-unchanged':  'pending',
    'would-skip':       'pending',
    'created':          'live',
    'updated':          'live',
    'unchanged':        'pending',
    'skipped':          'pending',
    'disabled':         'expired',
    'deleted':          'expired',
    'deleted-for-site': 'expired',
    'error':            'expired',
};

export function actionColor(action) {
    return ACTION_COLORS[action] || 'pending';
}
