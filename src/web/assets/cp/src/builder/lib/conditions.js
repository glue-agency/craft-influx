/**
 * The `showIf` vocabulary — whether a schema node is visible given the values
 * around it.
 *
 * Two renderers ask the question: {@see ../schema/SchemaForm.vue}, over a flat
 * auth/criteria schema, and {@see ../schema/MappingExtras.vue}, over a mapping
 * row's extras. They read their values from different places, so each passes a
 * `resolve` rather than the values themselves — but the CONDITION grammar is
 * one thing, and PHP writes it in one vocabulary (`SchemaBuilder`), so it is
 * evaluated in one place too.
 *
 * A condition names a handle plus at most one test:
 *
 *   {handle, equals: v}   the handle's value is exactly v
 *   {handle, in: [a, b]}  the handle's value is one of these
 *   {handle}              the handle's value is truthy
 *
 * A node's conditions are ANDed: every one must pass. No condition means always
 * visible, which is the overwhelming majority of nodes.
 */

/**
 * @param {Object} node A schema node, whose `showIf` is read (possibly absent).
 * @param {function(string): *} resolve Handle → its current value, which both
 * callers implement as "the saved value, falling back to the node's declared
 * default" — so a condition on an untouched control tests what the operator
 * actually sees.
 * @returns {boolean}
 */
export function isVisible(node, resolve) {
    return (node?.showIf || []).every((condition) => {
        if ('equals' in condition) {
            return resolve(condition.handle) === condition.equals;
        }

        if ('in' in condition) {
            return (condition.in || []).includes(resolve(condition.handle));
        }

        return !! resolve(condition.handle);
    });
}
