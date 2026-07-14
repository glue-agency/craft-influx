<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use Generator;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\sync\ItemProcessor;
use GlueAgency\Influx\sync\ItemResolution;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\web\ItemRowPresenter;
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
     * Shapes resolved elements + mapping results into the Twig/JS row arrays
     * the debug view renders. Kept separate from the orchestration here so the
     * presentation is unit-testable without booting a sync.
     */
    protected ItemRowPresenter $rows;

    public function init(): void
    {
        parent::init();
        $this->itemProcessor = new ItemProcessor();
        $this->rows = new ItemRowPresenter();
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
                'totalCount'     => $firstPage?->totalCount,
                'pageCount'      => $firstPage?->pageCount,
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
     *
     * $pinnedElementId, when given, resolves straight to that element instead
     * of re-deriving one from the match value — see {@see debugItem()}.
     *
     * $withParsedHtml is threaded down to the presenter so the log drill-down
     * can render rich parsed values server-side (element chips for relations,
     * a lightswitch for booleans); it defaults to false, leaving the streaming
     * debug path untouched.
     */
    public function inspectItem(
        Link $link,
        array $item,
        ?string $siteHandle = null,
        ?int $pinnedElementId = null,
        bool $withParsedHtml = false,
    ): array {
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

        return $this->debugItem($link, $target, $item, $siteHandle, $pinnedElementId, $withParsedHtml);
    }

    /**
     * One item through the shared {@see ItemProcessor} pipeline with
     * `dryRun: true` — resolve and populate run for real (in memory),
     * commit is never called. This method only presents the result; the
     * logic is the exact code the sync run executes.
     *
     * $pinnedElementId short-circuits the match-value lookup and resolves
     * straight to that element (any site). Without it, `resolve()` re-derives
     * the element from the match value scoped to `$siteHandle` — for the log
     * drill-down that's a problem specifically for element-triggered runs:
     * the log only carries the run's site (null there, since one run can span
     * several sites), so an unscoped match-value lookup is ambiguous whenever
     * more than one element shares that match value across sites (e.g. a
     * non-propagated section where each site has its own row with the same
     * import id). The log item DOES know which element the run actually
     * touched, so the drill-down pins to it directly instead of re-guessing.
     *
     * $withParsedHtml is passed straight through to the presenter's mapping
     * rendering (both call sites below) so a rich parsed value can render
     * server-side (chips, lightswitch); false on the streaming debug path.
     */
    protected function debugItem(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
        ?int $pinnedElementId = null,
        bool $withParsedHtml = false,
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
            $resolution = $pinnedElementId !== null
                ? $this->resolvePinned($link, $target, $remoteItem, $pinnedElementId)
                : $this->itemProcessor->resolve($context, $remoteItem);
        } catch (Throwable $e) {
            $row['error'] = 'findByMatchValue: ' . $e->getMessage();

            return $row;
        }

        $row['matchValue'] = $resolution->matchValue;

        if ($resolution->element) {
            $row['element'] = $this->rows->presentElement($resolution->element);
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
                    $row['mappings'] = $this->rows->presentMappingResults($preview->mappingResults, $resolution->element, $this->rows->fieldLabels($link, $target), $withParsedHtml);
                } catch (Throwable $e) {
                    $row['error'] = $e->getMessage();
                }
            }

            return $row;
        }

        $row['action'] = $result->action->dryRunLabel();

        if ($result->element !== null) {
            $row['mappings'] = $this->rows->presentMappingResults($result->mappingResults, $result->element, $this->rows->fieldLabels($link, $target), $withParsedHtml);
        }

        return $row;
    }

    /**
     * Resolve straight to a known element instead of re-deriving one from
     * the match value — see {@see debugItem()} for why. Looks the element up
     * across any site (`siteId: '*'`) since the log item doesn't record which
     * site row the original run touched, only which element.
     */
    protected function resolvePinned(Link $link, ElementTargetInterface $target, RemoteItem $item, int $elementId): ItemResolution
    {
        $matchValue = $link->matchValue($item);
        $element = Craft::$app->getElements()->getElementById($elementId, $target::elementType(), '*');

        return new ItemResolution($matchValue, $element, SyncDecision::decide($link, $matchValue, $element));
    }
}
