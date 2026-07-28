import { actionColorMap } from './vocabulary.js';

/**
 * Sync action → Craft status colour (live = green, pending = grey, expired =
 * red), for both the dry-run ("would-…") labels the debug inspector shows and
 * the committed labels the run log shows. The map itself is the server's
 * (ItemAction::color(), shipped via lib/vocabulary.js); an action outside it
 * reads as neutral.
 */
export function actionColor(action) {
    return actionColorMap()[action] || 'pending';
}
