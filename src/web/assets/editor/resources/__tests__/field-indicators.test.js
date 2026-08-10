import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it } from 'vitest';

/**
 * Behaviour spec for the element-editor field marks.
 *
 * The asset is an IIFE that runs on load, so each case builds the editor DOM it
 * wants and then evaluates the source — no module cache to reset between them.
 */
// Read from the project root rather than `import.meta.url`: under Vitest's
// transform this module's URL isn't a file: one.
const SOURCE = readFileSync('src/web/assets/editor/resources/field-indicators.js', 'utf8');

function runAsset(handles) {
    window.influxFieldIndicators = handles;

    // eslint-disable-next-line no-new-func
    new Function(SOURCE)();
}

function marks() {
    return document.querySelectorAll('.influx-field-indicator');
}

/** The wrapper Craft's field layout renders for a custom field. */
function fieldHtml(handle, inner) {
    return `
        <div id="fields-${handle}-field" class="field" data-attribute="${handle}">
            <div class="heading"><label id="fields-${handle}-label">A field</label></div>
            <div class="input">${inner}</div>
        </div>
    `;
}

describe('field indicators', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        delete window.influxFieldIndicators;
    });

    it('marks a mapped field once, inside its label', () => {
        document.body.innerHTML = fieldHtml('test_plain_text', '<input type="text">');

        runAsset(['test_plain_text']);

        expect(marks()).toHaveLength(1);
        expect(document.querySelector('#fields-test_plain_text-label .influx-field-indicator')).not.toBeNull();
    });

    /**
     * THE regression. Several Craft field types build their input from a
     * `Cp::*FieldHtml()` helper, which wraps it in a second `.field` whose id is
     * derived as `"<inputId>-field"` — the id the OUTER wrapper already carries. A
     * Color field with a palette does exactly that (`colorSelectFieldHtml`), so the
     * page holds two `#fields-test_color-field` nodes and `querySelectorAll`
     * returns both, where `getElementById` would stop at the first. Marking both
     * put a second icon inside the input, floating above the control.
     */
    it('marks a field whose input nests a duplicate wrapper id only once', () => {
        document.body.innerHTML = fieldHtml(
            'test_color',
            `<div id="fields-test_color-field" class="field">
                <div class="input"><select id="fields-test_color"></select></div>
             </div>`,
        );

        runAsset(['test_color']);

        expect(marks()).toHaveLength(1);
        expect(
            document.querySelector('.input .influx-field-indicator'),
            'the surviving mark belongs to the outer label, not the input',
        ).toBeNull();
    });

    /**
     * The outermost filter must not over-prune: a handle can legitimately appear
     * twice on a screen — once in the main pane, once in the sidebar meta — and
     * neither contains the other.
     */
    it('marks sibling occurrences of the same handle separately', () => {
        document.body.innerHTML = `
            <div class="pane">${fieldHtml('title', '<input type="text">')}</div>
            <div class="meta"><div id="title-field" class="field">
                <div class="heading"><label>Title</label></div>
            </div></div>
        `;

        runAsset(['title']);

        expect(marks()).toHaveLength(2);
    });

    /**
     * An asset's Filename is the one native Craft renders under a different name
     * than the handle a mapping writes: renaming an asset is a MOVE, so the field
     * is `data-attribute="newLocation"` with id `new-filename-field`, and nothing
     * on the page answers to `filename`. Before the alias, an asset link mapping
     * `filename` registered its handle and then decorated nothing at all.
     */
    it('marks an asset filename field through its newLocation alias', () => {
        document.body.innerHTML = `
            <div id="new-filename-field" class="field first" data-attribute="newLocation">
                <div class="heading"><label id="new-filename-label">Filename</label></div>
                <div class="input"><input id="new-filename" name="newFilename" type="text"></div>
            </div>
        `;

        runAsset(['filename']);

        expect(marks()).toHaveLength(1);
        expect(document.querySelector('#new-filename-label .influx-field-indicator')).not.toBeNull();
    });

    it('still marks a field that does answer to its own handle', () => {
        // The alias is additive — it must not stop a plain native from matching.
        document.body.innerHTML = `
            <div id="title-field" class="field" data-attribute="title">
                <div class="heading"><label id="title-label">Title</label></div>
                <div class="input"><input type="text"></div>
            </div>
        `;

        runAsset(['title']);

        expect(marks()).toHaveLength(1);
    });

    it('leaves unmapped fields alone', () => {
        document.body.innerHTML = fieldHtml('test_plain_text', '<input type="text">');

        runAsset(['something_else']);

        expect(marks()).toHaveLength(0);
    });

    it('does nothing without a registered handle list', () => {
        document.body.innerHTML = fieldHtml('test_plain_text', '<input type="text">');

        // eslint-disable-next-line no-new-func
        new Function(SOURCE)();

        expect(marks()).toHaveLength(0);
    });

    it('never stacks marks when the asset runs again', () => {
        document.body.innerHTML = fieldHtml('test_plain_text', '<input type="text">');

        runAsset(['test_plain_text']);
        runAsset(['test_plain_text']);

        expect(marks()).toHaveLength(1);
    });

    it('falls back to a native title tooltip where craft-tooltip is unavailable', () => {
        document.body.innerHTML = fieldHtml('test_plain_text', '<input type="text">');

        runAsset(['test_plain_text']);

        const mark = document.querySelector('.influx-field-indicator');
        expect(mark.title).toBe('This field is updated by Influx.');
        expect(mark.getAttribute('aria-label')).toBe('This field is updated by Influx.');
    });
});
