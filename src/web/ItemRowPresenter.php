<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\Cp;
use craft\helpers\Html;
use DateTimeInterface;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Shapes a single inspected item — its resolved element and per-field mapping
 * results — into the Twig/JS-facing row arrays the debug and log viewers
 * render. Extracted from {@see \GlueAgency\Influx\services\DebugService} so the
 * orchestration (the dry-run pipeline walk) stays in the service and the
 * presentation lives here, unit-testable in isolation.
 *
 * The array shapes emitted here are a contract with the Vue layer
 * (DebugItemDetail.vue) and its vitest specs — the row keys
 * (handle/label/node/default/native/rawValue/parsedValue/parsedHtml/
 * currentValue/changed/unaddressed/usedDefault/managedByTarget/error, and the
 * element chipHtml) and the 500-char value truncation must not drift. `parsedHtml` is the log viewer's opt-in
 * server-rendered variant of the parsed value — element chips for relations, a
 * lightswitch for booleans (see {@see presentMappingResults()}) — and is null
 * on every row unless that flag is set and the value has a rich rendering.
 *
 * Craft is only touched inside the methods (never the constructor), so the
 * presenter can be instantiated without a booted app.
 */
class ItemRowPresenter
{
    /**
     * Memoized handle => friendly-name maps, keyed by link, so a multi-item
     * dry-run resolves the target's mappable fields once rather than per item.
     *
     * @var array<string, array<string, string>>
     */
    protected array $fieldLabelCache = [];

    /**
     * Render {@see MappingResult}s into the Twig/JS-facing row shape —
     * values described (stringified, truncated), parsed values run through
     * the Craft field's normalizeValue for display parity with the editor.
     *
     * When `$withParsedHtml` is true, a parsed value with a richer display than
     * plain text also fills the row's `parsedHtml` key with server-rendered
     * markup: relation queries become Craft element chips (the
     * {@see elementChip()} seam, hyperlinked like the header chip) and booleans
     * become a display-only Craft lightswitch ({@see lightswitchHtml()}). The
     * flag defaults to false so the debug stream — which builds many rows per
     * run — never pays for the extra rendering; on every other row the key is
     * present but null, so the emitted shape stays uniform.
     *
     * @param list<\GlueAgency\Influx\sync\MappingResult> $results
     * @param array<string, string> $labels handle => friendly field name
     * @param bool $withParsedHtml render rich parsed values as server-side HTML too
     * @return list<array>
     */
    public function presentMappingResults(
        array $results,
        ElementInterface $element,
        array $labels = [],
        bool $withParsedHtml = false,
    ): array {
        $layout = $element->getFieldLayout();
        $rows = [];

        foreach ($results as $result) {
            $parsedValue = $result->parsedValue;

            if (! $result->native && $parsedValue !== null) {
                $craftField = $layout?->getFieldByHandle($result->handle);

                if ($craftField) {
                    try {
                        $parsedValue = $craftField->normalizeValue($parsedValue, $element);
                    } catch (Throwable) {
                        // Display-only nicety; fall back to the raw parse.
                    }
                }
            }

            // Log context only: values with a richer display than plain text
            // also render server-side — relations as element chips (fetched
            // once, rendered both ways; see describeElementChips()), booleans
            // as a display-only lightswitch. Everything else keeps the plain
            // stringified value and a null html key.
            $parsedHtml = null;

            if ($withParsedHtml && $parsedValue instanceof ElementQueryInterface) {
                [$parsedText, $parsedHtml] = $this->describeElementChips($parsedValue);
            } else {
                $parsedText = $this->describeValue($parsedValue);

                if ($withParsedHtml && is_bool($parsedValue)) {
                    $parsedHtml = $this->lightswitchHtml($parsedValue);
                }
            }

            $rows[] = [
                'handle'          => $result->handle,
                'label'           => $labels[$result->handle] ?? $result->handle,
                'node'            => $result->node,
                'default'         => $result->default,
                'native'          => $result->native,
                'rawValue'        => $this->describeValue($result->rawValue),
                'parsedValue'     => $parsedText,
                'parsedHtml'      => $parsedHtml,
                'currentValue'    => $this->describeValue($result->currentValue),
                'changed'         => $result->changed,
                'unaddressed'     => $result->unaddressed,
                'usedDefault'     => $result->usedDefault,
                'managedByTarget' => $result->managedByTarget,
                'error'           => $result->error,
            ];
        }

        return $rows;
    }

    /**
     * Handle => friendly field name for a link, sourced from the target's
     * mappable fields (the same labels the builder's mapping list shows).
     * Memoized per link so a multi-item dry-run resolves them once.
     *
     * @return array<string, string>
     */
    public function fieldLabels(Link $link, ElementTargetInterface $target): array
    {
        $key = $link->uid ?? $link->handle;

        if (! isset($this->fieldLabelCache[$key])) {
            $labels = [];

            foreach ($target->getMappableFields($link) as $field) {
                if (isset($field['handle'])) {
                    $labels[$field['handle']] = $field['name'] ?? $field['handle'];
                }
            }
            $this->fieldLabelCache[$key] = $labels;
        }

        return $this->fieldLabelCache[$key];
    }

    /**
     * @return list<array>
     */
    public function presentElement(ElementInterface $element): array
    {
        return [
            'id'        => $element->id,
            'title'     => (string) ($element->title ?? '#' . $element->id),
            'cpEditUrl' => $element->getCpEditUrl(),
            'siteId'    => $element->siteId,
            'chipHtml'  => $this->elementChip($element),
        ];
    }

    /**
     * The rendered Craft element chip HTML (a hyperlinked chip), the single
     * seam both the debug row and the log row draw their element markup from.
     */
    public function elementChip(ElementInterface $element): string
    {
        return Compat::elementChipHtml($element, ['hyperlink' => true]);
    }

    /**
     * A boolean parsed value as Craft's own lightswitch control — the way the
     * editor sees the field — instead of the 'true'/'false' text (which stays
     * on `parsedValue` as the plain fallback). Disabled so the control is
     * inert when the viewer injects it via v-html with no Craft JS init; its
     * on/off visual is pure CSS, carried by the `on` class.
     *
     * Cp::lightswitchHtml() is @since 4.0.0, so no Compat seam is needed.
     * Returns null when rendering fails (e.g. no booted app), degrading the
     * cell to the text fallback rather than breaking the row.
     */
    protected function lightswitchHtml(bool $on): ?string
    {
        try {
            return Cp::lightswitchHtml([
                'on'       => $on,
                'small'    => true,
                'disabled' => true,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Make a value safe to render in Twig — scalar through, objects/arrays to
     * a compact string representation. Truncated so a giant CKEditor blob
     * doesn't blow up the page.
     *
     * Several kinds get intentionally editor-friendly treatment before the
     * scalar/`__toString`/`json_encode` fallbacks, so the row reads like the
     * field does in the CP rather than like its raw storage:
     *
     * - Booleans render as the words `'true'`/`'false'`, not the `1`/`''` a
     *   plain `(string)` cast yields — a false lightswitch must not collapse
     *   to an empty cell the Vue layer can't tell from "no value".
     * - Option field data ({@see SingleOptionFieldData}, and the ArrayObject
     *   {@see MultiOptionsFieldData}) render as the option label per option
     *   (see {@see describeOption()}), so an editor sees "Te koop" rather than
     *   the bare stored value its `__toString` yields or the option-object
     *   JSON blob the multi-select would otherwise fall through to.
     * - Dates go through Craft's locale/timezone-aware formatter (see
     *   {@see describeDate()}) for parity with a real date field, degrading to
     *   a fixed format when no app is booted.
     */
    public function describeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $str = $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            $str = (string) $value;
        } elseif ($value instanceof DateTimeInterface) {
            $str = $this->describeDate($value);
        } elseif ($value instanceof ElementQueryInterface) {
            $str = $this->describeElements($value);
        } elseif ($value instanceof SingleOptionFieldData) {
            $str = $this->describeOption($value);
        } elseif ($value instanceof MultiOptionsFieldData) {
            $parts = [];

            foreach ($value as $option) {
                $parts[] = $this->describeOption($option);
            }

            $str = implode(', ', $parts);
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $str = (string) $value;
        } else {
            $str = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (strlen($str) > 500) {
            $str = substr($str, 0, 500) . '…';
        }

        return $str;
    }

    /**
     * Render one selected option as its label — the human-facing text the
     * editor picked, exactly what the dropdown itself shows in the CP. Falls
     * back to the bare stored value when the option has no usable label —
     * e.g. a stale/invalid stored value whose label is null — so an unknown
     * option degrades to its raw value instead of an empty string, and never
     * throws.
     */
    protected function describeOption(OptionData $option): string
    {
        $label = $option->label;

        if (is_string($label) && $label !== '') {
            return $label;
        }

        return (string) $option->value;
    }

    /**
     * Format a date the way the editor sees it in a Craft date field — through
     * the app's locale/timezone-aware formatter, short length (matching the
     * `asDatetime(..., 'short')` the CP log/link overviews use).
     *
     * Wrapped so the presenter keeps its "instantiable without a booted app"
     * property (see the class docblock): with no app the formatter reference
     * throws (a null-app call, or a missing `Craft` class in the pure unit
     * suite), and we fall back to the fixed `Y-m-d H:i:s` the column showed
     * before, rather than letting the whole row blow up.
     */
    protected function describeDate(DateTimeInterface $value): string
    {
        try {
            return Craft::$app->getFormatter()->asDatetime($value, 'short');
        } catch (Throwable) {
            return $value->format('Y-m-d H:i:s');
        }
    }

    /**
     * Render a relation query as human-readable element references
     * ("Werfkelder (#42), Kelder (#43)") rather than bare ids, so a mapping
     * from e.g. `building_type.id` shows the actual related element.
     *
     * Bounded on purpose — the debug stream calls describeValue() per row for
     * many items — so it fetches at most 6, shows the first 5, and appends an
     * ellipsis once a 6th exists. Falls back to the bare ids (or an empty list)
     * if the elements can't be resolved, and never throws.
     */
    protected function describeElements(ElementQueryInterface $query): string
    {
        try {
            $elements = $query->limit(6)->all();

            return $this->describeElementText(array_slice($elements, 0, 5), count($elements) > 5);
        } catch (Throwable) {
            return $this->describeElementIds($query);
        }
    }

    /**
     * The log drill-down's rendered variant of {@see describeElements()}: fetch
     * the relation's elements once (bounded identically — at most 6, showing the
     * first 5) and render them both ways from that single result set — as
     * concatenated Craft element chips (the {@see elementChip()} seam) and as
     * the plain-text reference fallback. A 6th element becomes a muted overflow
     * indicator on the chip side and the trailing "…" on the text side.
     *
     * Returns `[text, chipsHtml]`. Never throws — on a query failure it degrades
     * to the bare-id text (matching {@see describeElements()}) and a null chip
     * string, so a broken relation still renders as text rather than blank.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function describeElementChips(ElementQueryInterface $query): array
    {
        try {
            $elements = $query->limit(6)->all();
        } catch (Throwable) {
            return [$this->describeElementIds($query), null];
        }

        $shown = array_slice($elements, 0, 5);
        $overflow = count($elements) > 5;

        $chips = '';

        foreach ($shown as $element) {
            $chips .= $this->elementChip($element);
        }

        if ($overflow) {
            $chips .= Html::tag('span', '…', ['class' => 'light']);
        }

        return [$this->describeElementText($shown, $overflow), $chips];
    }

    /**
     * Render an already-fetched, already-bounded list of elements as the
     * "Werfkelder (#42), Kelder (#43)" reference text, appending the trailing
     * "…" when a further element was truncated. Shared by the text-only path
     * ({@see describeElements()}) and the chip path ({@see describeElementChips()})
     * so both describe the same fetched set identically.
     *
     * @param ElementInterface[] $elements the elements to show (already sliced to the display bound)
     */
    protected function describeElementText(array $elements, bool $overflow): string
    {
        $refs = [];

        foreach ($elements as $element) {
            $title = trim((string) ($element->title ?? ''));
            $refs[] = ($title !== '' ? $title . ' ' : '') . '(#' . $element->id . ')';
        }

        $str = implode(', ', $refs);

        if ($overflow) {
            $str .= ', …';
        }

        return $str;
    }

    /**
     * The pre-existing fallback rendering: the query's bare ids in brackets
     * ("[42, 43]"), or an empty list when even the ids can't be read.
     */
    protected function describeElementIds(ElementQueryInterface $query): string
    {
        $ids = [];

        try {
            $ids = $query->ids();
        } catch (Throwable) {
        }

        return '[' . implode(', ', array_map('strval', $ids)) . ']';
    }
}
