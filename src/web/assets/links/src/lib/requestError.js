/**
 * Unpack the human-readable message from a failed Craft.sendActionRequest
 * (axios-shaped) error: the controller's JSON `message` when the response
 * carried one, the transport error's own message otherwise, and the caller's
 * fallback when neither exists. Shared by DebugApp's inspect and LogApp's
 * item drill-down fetch so the unwrap order is defined once.
 */
export function requestErrorMessage(err, fallback) {
    return err?.response?.data?.message || err?.message || fallback;
}
