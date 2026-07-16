/**
 * Marks Influx-managed fields in the element editor.
 *
 * `window.influxFieldIndicators` is an array of field/attribute handles that an
 * active Influx mapping writes, registered by Influx::registerFieldIndicators()
 * only when the edited element has mapped fields. For each handle we find its
 * field wrapper in the editor DOM and drop a small icon next to the label,
 * whose hover popup explains the value is set by synchronisation. Handle-driven,
 * so it stays element-type-agnostic; idempotent, so slideouts / re-renders never
 * stack icons.
 *
 * The popup reuses Craft's <craft-tooltip> web component — the same one the
 * native "translated for each site" indicator uses — so it looks and behaves
 * identically. Craft 4 has no such component, so there we fall back to a plain
 * native title tooltip.
 */
(function () {
    'use strict';

    var LABEL = 'This field is updated by synchronisation';

    // "sync" glyph (Feather refresh-cw): the field's value is kept in sync from
    // the remote source, not the "link" chain used elsewhere.
    var ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<polyline points="23 4 23 10 17 10"/>' +
        '<polyline points="1 20 1 14 7 14"/>' +
        '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';

    function escape(handle) {
        return (window.CSS && CSS.escape) ? CSS.escape(handle) : handle;
    }

    // Custom fields render as #fields-<handle>-field with data-attribute=<handle>;
    // native attributes (title, …) as #<handle>-field. Collect every match so a
    // handle present in both the main pane and the sidebar meta is covered.
    function fieldsFor(handle) {
        var h = escape(handle);
        var selectors = [
            '.field[data-attribute="' + h + '"]',
            '#fields-' + h + '-field',
            '#' + h + '-field',
        ];
        var found = new Set();

        selectors.forEach(function (selector) {
            var nodes;
            try {
                nodes = document.querySelectorAll(selector);
            } catch (e) {
                return;
            }
            nodes.forEach(function (node) {
                found.add(node);
            });
        });

        return found;
    }

    function buildIndicator() {
        // A non-submitting button, mirroring Craft's own indicator: focusable
        // for a11y, prevent-autofocus so it never grabs focus on open.
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'influx-field-indicator prevent-autofocus';
        button.setAttribute('aria-label', LABEL);
        button.innerHTML = ICON;

        if (window.customElements && customElements.get('craft-tooltip')) {
            var tip = document.createElement('craft-tooltip');
            tip.setAttribute('placement', 'bottom');
            tip.setAttribute('max-width', '200px');
            tip.setAttribute('text', LABEL);
            tip.setAttribute('delay', '1000');
            tip.appendChild(button);

            return tip;
        }

        button.title = LABEL;

        return button;
    }

    function decorate(field) {
        if (field.hasAttribute('data-influx-indicated')) {
            return;
        }

        var heading = field.querySelector(':scope > .heading');
        var labelEl = heading ? heading.querySelector(':scope > label') : null;
        var indicator = buildIndicator();

        if (labelEl) {
            labelEl.insertAdjacentElement('afterend', indicator);
        } else if (heading) {
            heading.appendChild(indicator);
        } else {
            field.insertBefore(indicator, field.firstChild);
        }

        field.setAttribute('data-influx-indicated', '');
    }

    function run() {
        var handles = window.influxFieldIndicators;

        if (! Array.isArray(handles)) {
            return;
        }

        handles.forEach(function (handle) {
            fieldsFor(handle).forEach(function (field) {
                decorate(field);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
