<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
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
use Throwable;

/**
 * The shared per-item inspection engine: runs one remote item through the exact
 * {@see ItemProcessor} pipeline the real sync uses, but with `dryRun: true` so
 * nothing is written — no logs, no element saves, no cooldown marks — then
 * presents the resolved element + per-field mapping results as row arrays. The
 * Links overview "Debug" view drives it, fanning a whole first page through
 * {@see inspectWithTarget()} ({@see DebugService::inspectSite()}).
 *
 * It also answers the log detail's drill-down ({@see inspectStoredLogItem()}),
 * which is NOT an inspection: a historical row is presented from what the run
 * itself stored. Both live here because they emit one row shape
 * ({@see itemRow()}) for one Vue component.
 */
class InspectorService extends Component
{
    /**
     * The same pipeline the real sync runs ({@see \GlueAgency\Influx\sync\item\ItemRunner})
     * — invoked here with dry-run contexts and never committed. Injectable, like
     * {@see \GlueAgency\Influx\sync\item\ItemRunner::__construct()}'s, so a caller
     * can hand in a processor built around its own
     * {@see \GlueAgency\Influx\sync\item\MappingApplier} instead of inheriting
     * whichever one this service would have created.
     */
    protected ItemProcessor $itemProcessor;

    /**
     * Shapes resolved elements + mapping results into the Twig/JS row arrays
     * the debug view renders. Kept separate from the orchestration here so the
     * presentation is unit-testable without booting a sync.
     */
    protected ItemRowPresenter $rows;

    /**
     * `$config` stays last so Yii can still configure the component the normal
     * way ({@see \yii\base\Configurable}).
     */
    public function __construct(?ItemProcessor $itemProcessor = null, array $config = [])
    {
        $this->itemProcessor = $itemProcessor ?? new ItemProcessor();

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();
        $this->rows = new ItemRowPresenter();
    }

    /**
     * Drill-down for one stored log item, as the log viewer's detail pane
     * consumes it: `{row: array}`, or `{row: null, message: …}` when there is
     * nothing stored to show and the run's link is gone too.
     *
     * PRESENTED FROM STORAGE, not re-inspected. The run stored its own presented
     * mapping rows ({@see \GlueAgency\Influx\sync\item\ItemRunner::mappingSnapshot()}),
     * captured from the real {@see \GlueAgency\Influx\sync\item\MappingResult}s
     * the sync produced — so every per-row `changed` flag and every per-field
     * error is the genuine article, and the whole overlay dance this method used
     * to do is gone with the dry run that made it necessary. That dry run read
     * the element's PRESENT state: a successfully-updated item came back "no
     * change" on every row, a run-time failure (an asset upload, say) couldn't be
     * reproduced at all, and the honest values had to be stamped back on top
     * afterwards. Reading what the run wrote is both truthful and cheap — no
     * pipeline, no feed, no element save path.
     *
     * The row still carries the full {@see itemRow()} envelope the Vue component
     * renders, sourced accordingly: `action` / `message` / `matchValue` off the
     * record, `raw` from the stored payload, `mappings` from the snapshot,
     * `element` resolved fresh ({@see storedElement()}) so the chip and its edit
     * link reflect the element as it is now. `error` stays null — a run-time
     * failure is already the row's `message` or its per-field errors.
     *
     * Two degradations, both rendering rather than refusing:
     *   - nothing stored at all (a sweep row, which never had a payload) answers
     *     with the four-key subset the Vue side guards for;
     *   - a payload with no snapshot (rows written before the column existed, or
     *     one whose snapshot blew {@see \GlueAgency\Influx\services\LogsService::MAPPINGS_MAX_BYTES})
     *     renders its raw JSON with an empty field list and says so.
     *
     * A DELETED LINK no longer blocks any of that: the stored row is the source,
     * so only the link-derived match metadata drops out (null `matchAttribute` /
     * `matchNode`). It's still the answer when there's nothing stored either —
     * then there really is nothing to show.
     *
     * @return array{row: ?array, message?: string}
     */
    public function inspectStoredLogItem(LogItemRecord $item, LogRecord $log): array
    {
        $link = $this->linkFor($log);
        $raw = $this->storedPayload($item);
        $mappings = $this->storedMappings($item);

        if ($raw === null && $mappings === null) {
            if (! $link) {
                return [
                    'row'     => null,
                    'message' => Craft::t('influx', "Link '{handle}' no longer exists.", ['handle' => $log->linkHandle]),
                ];
            }

            return [
                'row' => [
                    'action'   => (string) $item->action,
                    'message'  => (string) ($item->message ?: Craft::t('influx', 'No stored payload for this item — drill-down was added after this run.')),
                    'mappings' => [],
                    'raw'      => null,
                ],
            ];
        }

        $message = $item->message ? (string) $item->message : null;

        if ($mappings === null) {
            $message ??= Craft::t('influx', 'No stored field data for this item — recorded before drill-down storage.');
        }

        $matchAttr = $link?->matchAttribute();
        $matchNode = $matchAttr !== null ? $link?->getMappingCollection()->get($matchAttr)?->node : null;
        $element = $this->storedElement($item, $log);

        return [
            'row' => self::itemRow([
                'matchAttribute' => $matchAttr,
                'matchNode'      => $matchNode,
                'matchValue'     => $item->matchValue !== null ? (string) $item->matchValue : null,
                'element'        => $element !== null ? $this->rows->presentElement($element) : null,
                'isNew'          => self::storedActionCreates((string) $item->action),
                'action'         => (string) $item->action,
                'message'        => $message,
                'raw'            => $raw,
                'mappings'       => $mappings ?? [],
            ]),
        ];
    }

    /**
     * Whether a stored action means the run brought the element into existence —
     * the drill-down's `isNew`. DERIVED FROM THE ACTION, because there is no
     * live pipeline behind a stored row to ask: the flag the sync computed isn't
     * a column, and the action it produced says the same thing. The dry-run
     * label is accepted alongside the committed one so the check reads the same
     * for either vocabulary.
     */
    protected static function storedActionCreates(string $action): bool
    {
        return in_array($action, [ItemAction::CREATED->value, ItemAction::CREATED->dryRunLabel()], true);
    }

    /**
     * The element a stored log item touched, loaded FRESH so its chip and edit
     * link show the element as it stands now — and null once it's been deleted,
     * which degrades the drill-down's header to the match value.
     *
     * Deliberately not {@see resolvePinned()}: that exists to hand the dry-run
     * pipeline a decision, needs the link's target, and re-derives the match
     * value from the payload — none of which a stored row needs, and the link may
     * be gone. The site handling is the same though, and for the same reason: load
     * in the run's own site so the edit link points at the row the run actually
     * touched, falling back to `'*'` when the element isn't propagated there (or
     * the run spanned sites and has no single one).
     *
     * The element type is left to Craft rather than taken from the link: the id
     * already determines it, and that keeps a since-deleted link from costing us
     * the chip.
     */
    protected function storedElement(LogItemRecord $item, LogRecord $log): ?ElementInterface
    {
        if (! $item->elementId) {
            return null;
        }

        $elementId = (int) $item->elementId;
        $siteId = $log->siteHandle !== null
            ? Craft::$app->getSites()->getSiteByHandle((string) $log->siteHandle)?->id
            : null;

        $elements = Craft::$app->getElements();
        $element = $elements->getElementById($elementId, null, $siteId ?? '*');

        if ($element === null && $siteId !== null) {
            $element = $elements->getElementById($elementId, null, '*');
        }

        return $element;
    }

    /**
     * The run's link, or null when it has since been deleted. On its own method
     * because it is {@see inspectStoredLogItem()}'s only reach outside the two
     * records it was handed — which is what lets that method be specced without a
     * booted plugin.
     */
    protected function linkFor(LogRecord $log): ?Link
    {
        return Influx::getInstance()->links->getLinkByHandle($log->linkHandle);
    }

    /**
     * A log item's stored mapping snapshot — the presented row tree the run
     * captured — or null when the row has none: a sweep row that never mapped
     * anything, a row written before the column existed, or one whose snapshot
     * was too big to store. Null is what makes the drill-down say so; an empty
     * list means "presented, maps no fields" and renders as such.
     *
     * @return list<array>|null
     */
    protected function storedMappings(LogItemRecord $item): ?array
    {
        if (! $item->mappings) {
            return null;
        }

        $decoded = json_decode($item->mappings, true);

        return is_array($decoded) ? $decoded : null;
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
     * link's element target first — the entry point for a caller holding just a
     * link and a payload. The debug inspector isn't one: it resolves the target
     * once for a whole page and calls {@see inspectWithTarget()} per item.
     *
     * $pinnedElementId, when given, resolves straight to that element instead
     * of re-deriving one from the match value — see {@see inspectWithTarget()}.
     *
     * $withParsedHtml is threaded down to the presenter so a caller can render
     * rich parsed values server-side (element chips for relations, a lightswitch
     * for booleans); it defaults to false, leaving the debug path untouched.
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
     * {@see inspectStoredLogItem()} deviates once: a log item with nothing
     * stored at all (a sweep row) has no envelope to fill, so it answers with a
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
     * from the match value scoped to `$siteHandle`, which is ambiguous whenever
     * the caller has no single site to scope by and more than one element shares
     * that match value across sites (e.g. a non-propagated section where each
     * site holds its own row with the same import id). A caller that already
     * KNOWS which element it means pins to it rather than re-guessing.
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
     * isn't propagated to that site, so the row still shows it.
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
