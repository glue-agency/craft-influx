/**
 * Pure helpers for the inspector's drill-down — the counts a children-bearing
 * mapping row summarises itself with, and the worst-state roll-up its wash and
 * label read from. Extracted so the rules exist once and are unit-testable
 * without mounting components.
 *
 * Deliberately free of `$t`: the labels these counts feed are literals in the
 * component that renders them, which is what lets the PHP catalogue be scanned
 * per component file.
 */

/**
 * Roll up every child of one children-bearing mapping row: the errors, missing
 * nodes and changes its drill label counts. A child that errored counts once
 * for its own action and once more per errored field row inside it — both are
 * things to go look at.
 *
 * @param {Object} mapping A mapping row carrying `children`.
 * @returns {{errors: number, missing: number, changes: number}}
 */
export function drillCounts(mapping) {
    const children = (mapping && mapping.children) || [];
    const rows = children.flatMap((child) => (child && child.mappings) || []);

    return {
        errors: children.filter((child) => child.action === 'error').length
            + rows.filter((row) => !! row.error).length,
        missing: rows.filter((row) => row.unaddressed === true).length,
        // Every flavour of add / remove / create / update is a change; only
        // 'unchanged' isn't.
        changes: children.filter((child) => child.action !== 'unchanged').length,
    };
}

/**
 * The worst state inside a children-bearing row: an error outranks a missing
 * node, which outranks a change. `null` for a row that drills into nothing, or
 * into nothing of note.
 *
 * @param {Object} mapping
 * @returns {'error'|'warn'|'changed'|null}
 */
export function drillState(mapping) {
    if (! mapping || ! mapping.children || ! mapping.children.length) {
        return null;
    }

    const { errors, missing, changes } = drillCounts(mapping);

    if (errors) {
        return 'error';
    }

    if (missing) {
        return 'warn';
    }

    return changes ? 'changed' : null;
}
