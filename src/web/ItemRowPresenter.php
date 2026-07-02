<?php

namespace GlueAgency\Influx\web;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
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
 * (DebugFields.vue / DebugItem.vue) and its vitest specs — the row keys
 * (handle/label/node/default/native/rawValue/parsedValue/currentValue/changed/
 * note/error, and the element chipHtml) and the 500-char value truncation must
 * not drift.
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
     * @param list<\GlueAgency\Influx\sync\MappingResult> $results
     * @param array<string, string> $labels handle => friendly field name
     * @return list<array>
     */
    public function presentMappingResults(array $results, ElementInterface $element, array $labels = []): array
    {
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

            $rows[] = [
                'handle'       => $result->handle,
                'label'        => $labels[$result->handle] ?? $result->handle,
                'node'         => $result->node,
                'default'      => $result->default,
                'native'       => $result->native,
                'rawValue'     => $this->describeValue($result->rawValue),
                'parsedValue'  => $this->describeValue($parsedValue),
                'currentValue' => $this->describeValue($result->currentValue),
                'changed'      => $result->changed,
                'note'         => $result->note,
                'error'        => $result->error,
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
     * Make a value safe to render in Twig — scalar through, objects/arrays to
     * a compact string representation. Truncated so a giant CKEditor blob
     * doesn't blow up the page.
     */
    public function describeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $str = (string) $value;
        } elseif ($value instanceof DateTimeInterface) {
            $str = $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof ElementQueryInterface) {
            $ids = [];

            try {
                $ids = $value->ids();
            } catch (Throwable) {
            }
            $str = '[' . implode(', ', array_map('strval', $ids)) . ']';
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
}
