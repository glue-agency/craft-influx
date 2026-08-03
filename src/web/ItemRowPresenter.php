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
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Shapes a single inspected item — its resolved element and per-field mapping
 * results — into the Twig/JS-facing row arrays the debug and log viewers
 * render. Extracted from {@see \GlueAgency\Influx\services\InspectorService} so the
 * orchestration (the dry-run pipeline walk) stays in the service and the
 * presentation lives here, unit-testable in isolation.
 *
 * The array shapes emitted here are a contract with the Vue layer
 * (DebugItemDetail.vue) and its vitest specs — the row keys
 * (handle/label/node/default/native/rawValue/parsedValue/parsedHtml/
 * currentValue/changed/unaddressed/usedDefault/managedByTarget/error/children/
 * childrenType, and the element chipHtml) and the 500-char value truncation must
 * not drift. `parsedHtml` is the log viewer's opt-in server-rendered variant of
 * the parsed value — element chips for relations, a lightswitch for booleans
 * (see {@see presentMappingResults()}) — and is null on every row unless that
 * flag is set and the value has a rich rendering.
 *
 * `children` is the row's nested drill-down — one entry per Matrix block or
 * related element, each carrying its own `mappings` in this very same row shape
 * (see {@see presentChildren()}) — and is null, alongside the `childrenType`
 * noun beside it, on every row that nests nothing.
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
     * Memoized handle => friendly-name maps for child rows, keyed by field-layout
     * id, so a Matrix field holding twenty blocks of one type — or a relation
     * holding twenty elements of one entry type — resolves those labels once.
     *
     * @var array<int, array<string, string>>
     */
    protected array $childLabelCache = [];

    /**
     * Render {@see MappingResult}s into the Twig/JS-facing row shape —
     * values described (stringified, truncated), parsed values run through
     * the Craft field's normalizeValue for display parity with the editor.
     * That normalization is a display-only nicety, so a field whose
     * normalizeValue throws falls back to the raw parse rather than failing
     * the row.
     *
     * When `$withParsedHtml` is true, a parsed value with a richer display than
     * plain text also fills the row's `parsedHtml` key with server-rendered
     * markup: relation queries become Craft element chips
     * ({@see describeElementChips()}, drawn from the {@see elementChip()} seam
     * and hyperlinked like the header chip) and booleans become a display-only
     * Craft lightswitch ({@see lightswitchHtml()}). Only the log drill-down
     * asks for that; the flag defaults to false so the debug stream — which
     * builds many rows per run — never pays for the extra rendering. Every
     * other value keeps its plain string and a null `parsedHtml`, so the
     * emitted shape stays uniform.
     *
     * A row that nests elements also carries them, presented
     * ({@see presentChildren()}) — every child's own field rows come back through
     * this same method, so the shape is recursive to whatever depth the walk
     * produced.
     *
     * @param list<MappingResult> $results
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
                    }
                }
            }

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
                'children'        => $this->presentChildren($result->children, $element, $withParsedHtml),
                'childrenType'    => $result->childrenType,
            ];
        }

        return $rows;
    }

    /**
     * Present a row's nested children — one entry per Matrix block or related
     * element, each with its own `mappings` in the same row shape the parent
     * rows use. Null in, null out: a row that nests nothing keeps its null.
     *
     * The labels are resolved from the CHILD's own layout
     * ({@see childFieldLabels()}) rather than reusing the map the parent rows
     * were given: that one holds the TARGET's mappable fields
     * ({@see fieldLabels()}), and a block type's or related element's fields
     * aren't in it — handing it down would label every child row by its bare
     * handle. A child whose rows aren't layout fields at all brings its own map
     * ({@see ChildResult::$labels}) — a Table row's cells are columns, which
     * only the Table field's own config can name — and that one wins outright.
     *
     * {@see ChildResult::$labelElement} is also the element the child rows'
     * normalizeValue display parity runs against, and it can be missing (a
     * removed block that couldn't be narrowed to an element), so the parent's
     * element stands in: the values still describe the same way, and the labels
     * simply fall back to handles.
     *
     * @param list<ChildResult>|null $children
     * @return list<array>|null
     */
    protected function presentChildren(?array $children, ElementInterface $fallbackElement, bool $withParsedHtml): ?array
    {
        if ($children === null) {
            return null;
        }

        $rows = [];

        foreach ($children as $child) {
            $rows[] = [
                'title'     => $child->title,
                'blockType' => $child->blockType,
                'element'   => $child->element !== null ? $this->presentElement($child->element) : null,
                'action'    => $child->action,
                'mappings'  => $this->presentMappingResults(
                    $child->mappingResults,
                    $child->labelElement ?? $fallbackElement,
                    $child->labels ?? $this->childFieldLabels($child->labelElement),
                    $withParsedHtml,
                ),
            ];
        }

        return $rows;
    }

    /**
     * Handle => friendly field name for one child's rows: its layout's own custom
     * fields, plus the native sub-rows a nested mapping can write (`title`,
     * `slug`), which are attributes and so never appear on a layout. Memoized by
     * layout id, skipping the cache when there is none to key on.
     *
     * The native labels translate, which needs a booted app, so they are guarded
     * the way {@see describeDate()} is — the presenter stays constructible and
     * callable without one (see the class docblock), degrading to the English
     * source strings instead of taking the row down.
     *
     * @return array<string, string>
     */
    protected function childFieldLabels(?ElementInterface $labelElement): array
    {
        $layout = $labelElement?->getFieldLayout();
        $key = $layout?->id;

        if ($key !== null && isset($this->childLabelCache[$key])) {
            return $this->childLabelCache[$key];
        }

        $labels = [];

        foreach ($layout?->getCustomFields() ?? [] as $field) {
            $labels[$field->handle] = $field->name ?: $field->handle;
        }

        try {
            $labels['title'] = Craft::t('app', 'Title');
            $labels['slug'] = Craft::t('app', 'Slug');
        } catch (Throwable) {
            $labels['title'] = 'Title';
            $labels['slug'] = 'Slug';
        }

        if ($key !== null) {
            $this->childLabelCache[$key] = $labels;
        }

        return $labels;
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
                $labels[$field->handle] = $field->name ?: $field->handle;
            }
            $this->fieldLabelCache[$key] = $labels;
        }

        return $this->fieldLabelCache[$key];
    }

    /**
     * @return array{id: ?int, title: string, cpEditUrl: ?string, siteId: ?int, chipHtml: string}
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
     * `Cp::lightswitchHtml()` needs no Craft 4/5 Compat seam — it is available
     * since Craft 4.0, ahead of the supported range. The `try`/`catch` guards
     * something else: rendering can still fail for reasons unrelated to the
     * API existing (no booted app, no CP request context — as in the unit
     * suite), and returning null then degrades that cell to its plain text
     * fallback rather than breaking the whole row.
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
