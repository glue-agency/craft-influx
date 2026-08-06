/**
 * THE map from a schema node's `type` to the component that renders it.
 *
 * PHP owns the type vocabulary (schema/SchemaBuilder.php); this owns which `.vue`
 * file serves it. Two consequences worth the split: renaming a component is never
 * a PHP change, and adding a control kind is a component plus one line here —
 * no branch anywhere, in any region.
 *
 * A type nothing claims falls back to {@see TextField}, so a third-party kind
 * pushed through `SchemaBuilder::node()` still renders labeled and still
 * reads/writes its slot instead of vanishing.
 *
 * Which SLOT a node writes is not here — that follows from the region it renders
 * in, and `lib/slots.js` owns it.
 */

import ElementField from './inputs/ElementField.vue';
import ElementSubFields from './inputs/ElementSubFields.vue';
import IconField from './inputs/IconField.vue';
import LightswitchField from './inputs/LightswitchField.vue';
import MatrixFields from './inputs/MatrixFields.vue';
import NoteField from './inputs/NoteField.vue';
import SelectField from './inputs/SelectField.vue';
import SubFields from './inputs/SubFields.vue';
import TextField from './inputs/TextField.vue';
// Third-party controls live with the PHP strategy they serve, not in inputs/.
import TableMakerColumns from '@integrations/verbb/tablemaker/resources/TableMakerColumns.vue';
import TokenField from './inputs/TokenField.vue';

export const CONTROLS = {
    // Leaf controls: one value in, one value out.
    text: TextField,
    code: TextField,
    select: SelectField,
    multiSelect: SelectField,
    lightswitch: LightswitchField,
    tokenInput: TokenField,
    element: ElementField,
    icon: IconField,
    note: NoteField,
    // Containers: a whole stored channel in, the same channels out. Which ones
    // each binds is `channelsFor()` in lib/slots.js.
    subFields: SubFields,
    elementSubFields: ElementSubFields,
    matrixFields: MatrixFields,
    tableMakerColumns: TableMakerColumns,
};

/**
 * The component for a node, or the text fallback.
 *
 * @param {Object} node
 * @returns {Object}
 */
export function controlFor(node) {
    return CONTROLS[node?.type] || CONTROLS.text;
}
