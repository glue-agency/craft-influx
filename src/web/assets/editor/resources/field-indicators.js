/**
 * Marks Influx-managed fields in the element editor.
 *
 * `window.influxFieldIndicators` is a map of field/attribute handle → the
 * name(s) of the Influx link(s) that write it, registered by
 * Influx::registerFieldIndicators() only when the edited element has mapped
 * fields. For each handle we find its field wrapper in the editor DOM and drop
 * a small link icon next to the label, its tooltip naming the responsible
 * link(s). Handle-driven, so it stays element-type-agnostic; idempotent, so
 * slideouts / re-renders never stack icons.
 */
(function () {
    'use strict';

    // Feather "link" icon — echoes Influx's core "Link" concept.
    var ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"' +
        ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>' +
        '<path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';

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

    function buildIndicator(linkNames) {
        var label = linkNames.length === 1
            ? 'Managed by Influx link: ' + linkNames[0]
            : 'Managed by Influx links: ' + linkNames.join(', ');

        var badge = document.createElement('span');
        badge.className = 'influx-field-indicator';
        badge.setAttribute('role', 'img');
        badge.setAttribute('aria-label', label);
        badge.setAttribute('title', label);
        badge.innerHTML = ICON;

        return badge;
    }

    function decorate(field, linkNames) {
        if (field.hasAttribute('data-influx-indicated')) {
            return;
        }

        var heading = field.querySelector(':scope > .heading');
        var labelEl = heading ? heading.querySelector(':scope > label') : null;
        var badge = buildIndicator(linkNames);

        if (labelEl) {
            labelEl.insertAdjacentElement('afterend', badge);
        } else if (heading) {
            heading.appendChild(badge);
        } else {
            field.insertBefore(badge, field.firstChild);
        }

        field.setAttribute('data-influx-indicated', '');
    }

    function run() {
        var map = window.influxFieldIndicators;

        if (!map || typeof map !== 'object') {
            return;
        }

        Object.keys(map).forEach(function (handle) {
            var linkNames = map[handle] || [];

            fieldsFor(handle).forEach(function (field) {
                decorate(field, linkNames);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run, { once: true });
    } else {
        run();
    }
})();
