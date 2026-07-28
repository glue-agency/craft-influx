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

    var LABEL = 'This field is updated by Influx.';

    // The Influx mark (the design's field-indicator variant): stacked inflow
    // bars fading into the destination. Filled shapes in currentColor, so it
    // tints like a native CP icon and renders under Craft's mask treatment.
    var ICON =
        '<svg viewBox="0 0 100 100" fill="currentColor" aria-hidden="true">' +
        '<rect x="20" y="14" width="60" height="20" rx="10"/>' +
        '<rect x="20" y="44" width="60" height="20" rx="10" opacity="0.6"/>' +
        '<rect x="20" y="74" width="60" height="20" rx="10" opacity="0.3"/></svg>';

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
        var label = heading ? heading.querySelector(':scope > label, :scope > legend') : null;
        var indicator = buildIndicator();

        // Inside the label, after Craft's own indicators (the required marker and
        // the translation craft-tooltip), so ours sits right alongside them.
        if (label) {
            label.appendChild(indicator);
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
