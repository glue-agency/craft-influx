<?php

namespace GlueAgency\Influx\services;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use DateTimeInterface;
use Generator;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\sync\ItemProcessor;
use GlueAgency\Influx\sync\ItemResolution;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use Throwable;

/**
 * Strict dry-run inspector for a link. Mirrors what SynchronizationService
 * would do for the first page of items, but writes nothing — no logs, no
 * element saves, no cooldown marks. Used by the CP "Debug" view on the
 * Links overview.
 */
class DebugService extends Component
{
    public const DEFAULT_LIMIT = 10;

    /**
     * The same pipeline {@see SynchronizationService} runs — invoked here
     * with dry-run contexts and never committed.
     */
    protected ItemProcessor $itemProcessor;

    /**
     * Memoized handle => friendly-name maps, keyed by link, so a multi-item
     * dry-run resolves the target's mappable fields once rather than per item.
     *
     * @var array<string, array<string, string>>
     */
    protected array $fieldLabelCache = [];

    public function init(): void
    {
        parent::init();
        $this->itemProcessor = new ItemProcessor();
    }

    /**
     * Per-site dry-run, yielding events suitable for Server-Sent Events:
     *
     *   ['type' => 'meta',  'data' => [...]]   — site metadata, once at start
     *   ['type' => 'item',  'data' => [...]]   — one per processed item
     *   ['type' => 'error', 'data' => [...]]   — non-recoverable fetch/setup error
     *
     * The generator finishes naturally when the first page is exhausted or the
     * limit is reached; callers should send their own "done" sentinel.
     */
    public function streamSite(Link $link, ?string $siteHandle, int $limit, ?string $offset = null): Generator
    {
        $plugin = Influx::getInstance();
        $target = $plugin->targets->forLink($link);

        $matchAttr = $link->matchAttribute();
        $matchNode = $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null;

        [$queryParams, $offsetLabel] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        if (! $target) {
            yield [
                'type' => 'error',
                'data' => [
                    'message' => "No element target registered for '{$link->elementType}'.",
                ],
            ];

            return;
        }

        $url = $plugin->data->endpoints()->listUrlForDisplay($link, $siteHandle, $queryParams);

        // Same iterator the sync run walks — the debug view just stops after
        // the first page.
        $firstPage = null;

        try {
            foreach ($plugin->data->pages($link, $siteHandle, $queryParams) as $page) {
                $firstPage = $page;

                break;
            }
        } catch (Throwable $e) {
            yield [
                'type' => 'meta',
                'data' => [
                    'siteHandle'     => $siteHandle,
                    'url'            => $url,
                    'itemsOnPage'    => 0,
                    'paginatorNode'  => $link->paginatorNode,
                    'paginatorValue' => null,
                    'limit'          => $limit,
                    'matchAttribute' => $matchAttr,
                    'matchNode'      => $matchNode,
                    'offset'         => $offset,
                    'offsetLabel'    => $offsetLabel,
                    'offsetQuery'    => $queryParams,
                    'error'          => $e->getMessage(),
                ],
            ];

            return;
        }

        yield [
            'type' => 'meta',
            'data' => [
                'siteHandle'     => $siteHandle,
                'url'            => $url,
                'itemsOnPage'    => $firstPage ? count($firstPage->items) : 0,
                'paginatorNode'  => $link->paginatorNode,
                'paginatorValue' => $firstPage?->nextUrl,
                'limit'          => $limit,
                'matchAttribute' => $matchAttr,
                'matchNode'      => $matchNode,
                'offset'         => $offset,
                'offsetLabel'    => $offsetLabel,
                'offsetQuery'    => $queryParams,
                'error'          => null,
            ],
        ];

        if (! $firstPage) {
            return;
        }

        $index = 0;

        foreach (array_slice($firstPage->items, 0, $limit) as $item) {
            $row = $this->debugItem($link, $target, $item->raw(), $siteHandle);
            $row['index'] = $index++;
            yield ['type' => 'item', 'data' => $row];
        }
    }

    /**
     * Public entry point: run the per-item inspection against an already-fetched
     * remote item. Used by the log detail drill-down to reuse the debug
     * machinery against a historical row's stored payload.
     */
    public function inspectItem(Link $link, array $item, ?string $siteHandle = null): array
    {
        $target = Influx::getInstance()->targets->forLink($link);

        if (! $target) {
            return [
                'matchAttribute' => $link->matchAttribute(),
                'matchNode'      => null,
                'matchValue'     => null,
                'element'        => null,
                'isNew'          => false,
                'action'         => 'error',
                'message'        => null,
                'raw'            => $item,
                'mappings'       => [],
                'error'          => "No element target registered for '{$link->elementType}'.",
            ];
        }

        return $this->debugItem($link, $target, $item, $siteHandle);
    }

    /**
     * One item through the shared {@see ItemProcessor} pipeline with
     * `dryRun: true` — resolve and populate run for real (in memory),
     * commit is never called. This method only presents the result; the
     * logic is the exact code the sync run executes.
     */
    protected function debugItem(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
    ): array {
        $context = SyncContext::forSite($link, $target, $siteHandle, dryRun: true);
        $remoteItem = new RemoteItem($item);

        $matchAttr = $link->matchAttribute();
        $row = [
            'matchAttribute' => $matchAttr,
            'matchNode'      => $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null,
            'matchValue'     => null,
            'element'        => null,
            'isNew'          => false,
            'action'         => 'would-skip',
            'message'        => null,
            'raw'            => $item,
            'mappings'       => [],
            'error'          => null,
        ];

        try {
            $resolution = $this->itemProcessor->resolve($context, $remoteItem);
        } catch (Throwable $e) {
            $row['error'] = 'findByMatchValue: ' . $e->getMessage();

            return $row;
        }

        $row['matchValue'] = $resolution->matchValue;

        if ($resolution->element) {
            $row['element'] = $this->describeElement($resolution->element);
        }

        try {
            $result = $this->itemProcessor->populate($context, $remoteItem, $resolution);
        } catch (Throwable $e) {
            // populate() only throws from the target's buildNew() — mapping
            // errors are captured per-row by the applier.
            $row['isNew'] = $resolution->decision === SyncDecision::CREATE;
            $row['action'] = $row['isNew'] ? ItemAction::CREATED->dryRunLabel() : ItemAction::UPDATED->dryRunLabel();
            $row['error'] = 'buildNew: ' . $e->getMessage();

            return $row;
        }

        $row['isNew'] = $result->isNew;

        if ($result->decision->isSkip()) {
            $row['action'] = ItemAction::SKIPPED->dryRunLabel();
            $row['message'] = $result->message;

            // A skipped-but-existing element still gets its mapping rows
            // rendered so the user can see what an enabled 'update' would
            // do — run a preview populate with a forced Update decision
            // (dry-run, so nothing is written).
            if ($result->decision === SyncDecision::SKIP_NO_UPDATE && $resolution->element !== null) {
                try {
                    $preview = $this->itemProcessor->populate(
                        $context,
                        $remoteItem,
                        new ItemResolution($resolution->matchValue, $resolution->element, SyncDecision::UPDATE),
                    );
                    $row['mappings'] = $this->presentMappingResults($preview->mappingResults, $resolution->element, $this->fieldLabels($link, $target));
                } catch (Throwable $e) {
                    $row['error'] = $e->getMessage();
                }
            }

            return $row;
        }

        $row['action'] = $result->action->dryRunLabel();

        if ($result->element !== null) {
            $row['mappings'] = $this->presentMappingResults($result->mappingResults, $result->element, $this->fieldLabels($link, $target));
        }

        return $row;
    }

    /**
     * Render {@see MappingResult}s into the Twig/JS-facing row shape —
     * values described (stringified, truncated), parsed values run through
     * the Craft field's normalizeValue for display parity with the editor.
     *
     * @param list<\GlueAgency\Influx\sync\MappingResult> $results
     * @param array<string, string> $labels handle => friendly field name
     * @return list<array>
     */
    protected function presentMappingResults(array $results, ElementInterface $element, array $labels = []): array
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
    protected function fieldLabels(Link $link, ElementTargetInterface $target): array
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
    protected function describeElement(ElementInterface $element): array
    {
        return [
            'id'        => $element->id,
            'title'     => (string) ($element->title ?? '#' . $element->id),
            'cpEditUrl' => $element->getCpEditUrl(),
            'siteId'    => $element->siteId,
            'chipHtml'  => Compat::elementChipHtml($element, ['hyperlink' => true]),
        ];
    }

    /**
     * Make a value safe to render in Twig — scalar through, objects/arrays to
     * a compact string representation. Truncated so a giant CKEditor blob
     * doesn't blow up the page.
     */
    protected function describeValue(mixed $value): ?string
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
