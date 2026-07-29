<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\sync\item\ItemProcessor;
use GlueAgency\Influx\sync\item\ItemResolution;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\web\ItemRowPresenter;
use GlueAgency\Influx\web\LogPresenter;
use Throwable;

/**
 * The shared per-item inspection engine: runs one remote item through the exact
 * {@see ItemProcessor} pipeline the real sync uses, but with `dryRun: true` so
 * nothing is written — no logs, no element saves, no cooldown marks — then
 * presents the resolved element + per-field mapping results as row arrays.
 *
 * Two consumers share this one engine so the logic exists exactly once: the
 * Links overview "Debug" view ({@see DebugService::inspectSite()}, which fans a
 * whole first page through {@see inspectWithTarget()}) and the log detail
 * drill-down ({@see inspectStoredLogItem()}, one historical stored payload).
 */
class InspectorService extends Component
{
    /**
     * The same pipeline the real sync runs ({@see \GlueAgency\Influx\sync\item\ItemRunner})
     * — invoked here with dry-run contexts and never committed.
     */
    protected ItemProcessor $itemProcessor;

    /**
     * Shapes resolved elements + mapping results into the Twig/JS row arrays
     * the debug view renders. Kept separate from the orchestration here so the
     * presentation is unit-testable without booting a sync.
     */
    protected ItemRowPresenter $rows;

    /**
     * Owns the stored-vs-recomputed overlays the log drill-down needs
     * ({@see inspectStoredLogItem()}).
     */
    protected LogPresenter $logRows;

    public function init(): void
    {
        parent::init();
        $this->itemProcessor = new ItemProcessor();
        $this->rows = new ItemRowPresenter();
        $this->logRows = new LogPresenter();
    }

    /**
     * Drill-down for one stored log item, as the log viewer's detail pane
     * consumes it: `{row: array}`, or `{row: null, message: …}` when the run's
     * link has since been deleted and there's nothing to inspect against.
     *
     * Re-runs the debug-view inspection against the raw remote payload captured
     * when the item was synced, so the user can see per-field source/parsed/
     * current values and which mappings would (re-)apply if synced again. Pins to
     * the item's own `elementId` rather than re-deriving the element from the
     * match value — `$log->siteHandle` is null for element-triggered runs, so an
     * unscoped match-value lookup would be ambiguous whenever the same match
     * value exists on more than one element across sites.
     *
     * The stored run-time field errors and "changed" flags are then overlaid on
     * top of that fresh inspection, because the stored values are the
     * authoritative ones: a dry run reads the element's LIVE state, so it can't
     * reproduce e.g. an asset-upload failure, and an item that was already
     * updated would falsely read "no change". A null `changedFields` column
     * resets the rows to the viewer's "?" state.
     *
     * An item with no stored payload — swept missing-element rows have none, and
     * older runs predate payload storage — still returns a real row so the
     * drill-down renders normally.
     *
     * @return array{row: ?array, message?: string}
     */
    public function inspectStoredLogItem(LogItemRecord $item, LogRecord $log): array
    {
        $link = Influx::getInstance()->links->getLinkByHandle($log->linkHandle);

        if (! $link) {
            return [
                'row'     => null,
                'message' => Craft::t('influx', "Link '{handle}' no longer exists.", ['handle' => $log->linkHandle]),
            ];
        }

        $raw = $this->storedPayload($item);

        if ($raw === null) {
            return [
                'row' => [
                    'action'   => (string) $item->action,
                    'message'  => (string) ($item->message ?: Craft::t('influx', 'No stored payload for this item — drill-down was added after this run.')),
                    'mappings' => [],
                    'raw'      => null,
                ],
            ];
        }

        $row = $this->inspectItem(
            $link,
            $raw,
            $log->siteHandle,
            $item->elementId !== null ? (int) $item->elementId : null,
            withParsedHtml: true,
        );
        $row['action'] = (string) $item->action;

        if ($item->message) {
            $row['message'] = (string) $item->message;
        }

        $mappings = $this->logRows->overlayFieldErrors(
            $row['mappings'] ?? [],
            $this->logRows->fieldErrors($item->fieldErrors),
        );
        $row['mappings'] = $this->logRows->overlayChangedFlags($mappings, $item->changedFields);

        return ['row' => $row];
    }

    /**
     * A log item's stored remote payload, or null when it has none / the stored
     * JSON isn't an array.
     */
    protected function storedPayload(LogItemRecord $item): ?array
    {
        if (! $item->payload) {
            return null;
        }

        $decoded = json_decode($item->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Inspect an already-fetched remote item against a link, resolving the
     * link's element target first. Used by the log detail drill-down to reuse
     * the inspection machinery against a historical row's stored payload;
     * callers that already hold the target (the debug inspector) skip the
     * lookup and call {@see inspectWithTarget()} directly.
     *
     * $pinnedElementId, when given, resolves straight to that element instead
     * of re-deriving one from the match value — see {@see inspectWithTarget()}.
     *
     * $withParsedHtml is threaded down to the presenter so the log drill-down
     * can render rich parsed values server-side (element chips for relations,
     * a lightswitch for booleans); it defaults to false, leaving the debug
     * path untouched.
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
            return self::itemRow([
                'matchAttribute' => $link->matchAttribute(),
                'action'         => 'error',
                'raw'            => $item,
                'error'          => "No element target registered for '{$link->elementType}'.",
            ]);
        }

        return $this->inspectWithTarget($link, $target, $item, $siteHandle, $pinnedElementId, $withParsedHtml);
    }

    /**
     * THE inspector-row shape — one item as both drill-downs render it
     * (DebugItemDetail.vue on the Vue side), with $values overriding whichever
     * slots the caller resolved. Declared once here rather than repeated per
     * build path, and pinned from both languages against
     * `src/web/assets/cp/tests/fixtures/inspector-row.json`
     * ({@see \GlueAgency\Influx\Tests\unit\services\InspectorRowPayloadTest} plus
     * the SPA's `components/__tests__/inspector-row.contract.test.js`).
     *
     * The defaults describe an item nothing has resolved yet: no element, no
     * mapping rows, and the dry-run skip action a run that decides nothing lands
     * on.
     *
     * {@see inspectStoredLogItem()} deviates once: an item saved before
     * drill-down existed has no payload to inspect, so it answers with a
     * four-key subset (action / message / mappings / raw) that the Vue side
     * guards for.
     */
    public static function itemRow(array $values = []): array
    {
        return array_merge([
            'matchAttribute' => null,
            'matchNode'      => null,
            'matchValue'     => null,
            'element'        => null,
            'isNew'          => false,
            'action'         => 'would-skip',
            'message'        => null,
            'raw'            => [],
            'mappings'       => [],
            'error'          => null,
        ], $values);
    }

    /**
     * One item through the shared {@see ItemProcessor} pipeline with
     * `dryRun: true` — resolve and populate run for real (in memory),
     * commit is never called. This method only presents the result; the
     * logic is the exact code the sync run executes. The target is passed in
     * pre-resolved so the debug inspector resolves it once for a whole page.
     *
     * $pinnedElementId short-circuits the match-value lookup and resolves
     * straight to that element. Without it, `resolve()` re-derives the element
     * from the match value scoped to `$siteHandle` — for the log drill-down
     * that's a problem specifically for element-triggered runs: the log only
     * carries the run's site (null there, since one run can span several sites),
     * so an unscoped match-value lookup is ambiguous whenever more than one
     * element shares that match value across sites (e.g. a non-propagated
     * section where each site has its own row with the same import id). The log
     * item DOES know which element the run actually touched, so the drill-down
     * pins to it directly instead of re-guessing.
     *
     * $withParsedHtml is passed straight through to the presenter's mapping
     * rendering (both call sites below) so a rich parsed value can render
     * server-side (chips, lightswitch); false on the debug path.
     *
     * `populate()` can only throw out of `buildNew()` — mapping errors are
     * captured per row — hence the `buildNew:` prefix on that error. An item
     * that is skipped but already exists additionally previews a forced Update,
     * so the user sees what enabling `update` would do.
     */
    public function inspectWithTarget(
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
        $row = self::itemRow([
            'matchAttribute' => $matchAttr,
            'matchNode'      => $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null,
            'raw'            => $item,
        ]);

        try {
            $resolution = $pinnedElementId !== null
                ? $this->resolvePinned($link, $target, $remoteItem, $pinnedElementId, $context->siteId)
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
            $row['isNew'] = $resolution->decision === SyncDecision::CREATE;
            $row['action'] = $row['isNew'] ? ItemAction::CREATED->dryRunLabel() : ItemAction::UPDATED->dryRunLabel();
            $row['error'] = 'buildNew: ' . $e->getMessage();

            return $row;
        }

        $row['isNew'] = $result->isNew;

        if ($result->decision->isSkip()) {
            $row['action'] = ItemAction::SKIPPED->dryRunLabel();
            $row['message'] = $result->message;

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
     * the match value — see {@see inspectWithTarget()} for why.
     *
     * Loads the element in the run's own site (`$siteId`) when it had one, so
     * the presented chip's edit link points at the site row the run actually
     * touched — not whichever site `'*'` happens to sort first (which surfaces
     * a foreign-language element for a site-scoped run). `$siteId` is null for
     * runs with no single site (element-triggered / all-sites); those fall back
     * to `'*'`. The site-specific load also falls back to `'*'` if the element
     * isn't propagated to that site, so the drill-down still shows it.
     */
    protected function resolvePinned(Link $link, ElementTargetInterface $target, RemoteItem $item, int $elementId, ?int $siteId = null): ItemResolution
    {
        $matchValue = $link->matchValue($item);
        $elements = Craft::$app->getElements();
        $element = $elements->getElementById($elementId, $target::elementType(), $siteId ?? '*');

        if ($element === null && $siteId !== null) {
            $element = $elements->getElementById($elementId, $target::elementType(), '*');
        }

        return new ItemResolution($matchValue, $element, SyncDecision::decide($link, $matchValue, $element));
    }
}
