<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use GlueAgency\Influx\web\assets\links\StylesAsset;
use GlueAgency\Influx\web\ItemRowPresenter;
use GlueAgency\Influx\web\LogPresenter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends AbstractController
{
    public const ITEMS_PER_PAGE = 25;

    /**
     * Statuses the overview's status filter offers, in display order. A
     * requested status outside this set is ignored (treated as "all").
     */
    protected const FILTER_STATUSES = ['running', 'ok', 'error'];

    public function actionIndex(): Response
    {
        // The overview reuses the plugin's server-rendered pill classes, which
        // live in links.css (the CSS-only slice).
        Craft::$app->getView()->registerAssetBundle(StylesAsset::class);

        $page = $this->intQueryParam('page', 1, 1);
        $perPage = 50;

        $plugin = Influx::getInstance();

        // handle => id / handle => name, so each row can link to its link's
        // edit screen by id and show its friendly name (logs only store the
        // handle); a deleted link is absent from both maps.
        $links = $plugin->links->getAllLinks();
        $linkIds = array_map(static fn($link)   => $link->id, $links);
        $linkNames = array_map(static fn($link) => $link->name, $links);

        // Toolbar filters. Both are validated against what actually exists so a
        // stale/hand-edited query string just falls back to "all".
        $selectedLink = $this->stringQueryParam('link');

        if ($selectedLink !== null && ! isset($linkNames[$selectedLink])) {
            $selectedLink = null;
        }

        $selectedStatus = $this->stringQueryParam('status');

        if ($selectedStatus !== null && ! in_array($selectedStatus, self::FILTER_STATUSES, true)) {
            $selectedStatus = null;
        }

        $selectedTrigger = $this->stringQueryParam('trigger');

        if ($selectedTrigger !== null && SyncTrigger::tryFrom($selectedTrigger) === null) {
            $selectedTrigger = null;
        }

        // value => label for the trigger filter, in enum declaration order.
        $triggers = [];

        foreach (SyncTrigger::cases() as $trigger) {
            $triggers[$trigger->value] = $trigger->label();
        }

        ['logs' => $logs, 'total' => $total] = $plugin->logs->paginate($page, $perPage, $selectedLink, $selectedStatus, $selectedTrigger);

        return $this->renderTemplate('influx/logs/index', [
            'logs'            => $logs,
            'page'            => $page,
            'perPage'         => $perPage,
            'total'           => $total,
            'linkIds'         => $linkIds,
            'linkNames'       => $linkNames,
            'presenter'       => new LogPresenter(),
            'selectedLink'    => $selectedLink,
            'selectedStatus'  => $selectedStatus,
            'statuses'        => self::FILTER_STATUSES,
            'selectedTrigger' => $selectedTrigger,
            'triggers'        => $triggers,
            'retentionDays'   => $plugin->getSettings()->logRetentionDays,
        ]);
    }

    public function actionView(int $id): Response
    {
        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        // The viewer is a Vue app (LogApp) — ship the full bundle.
        Craft::$app->getView()->registerAssetBundle(LinksAsset::class);

        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();

        // The link this log belongs to (null if it has since been deleted) —
        // logs only store the handle, so its id + name ride along in the config
        // for the header's "back to link" cross-link. Its element type also
        // lets the presenter batch-load the page's elements in one query.
        $link = $plugin->links->getLinkByHandle($log->linkHandle);

        // Only the first page rides along in the bootstrap — the rest is paged
        // in from actionItems(), so a huge run doesn't ship as one JSON blob.
        $items = $presenter->presentItems($plugin->logs->itemPage($log, [], 0, self::ITEMS_PER_PAGE), $link?->elementType);

        // The endpoint(s) this run fetched from. Four cases:
        //   - single-element run ($log->elementId set): the item endpoint
        //     template (tokens vary per element/site, so the template is the
        //     honest constant);
        //   - site-scoped run ($log->siteHandle set): the one site endpoint it
        //     used (falling back to the base if that site has no dedicated one);
        //   - all-sites run on a link WITH per-site endpoints: no single URL is
        //     right (the base is never fetched), so list every site endpoint;
        //   - all-sites run with no site endpoints: the base endpoint.
        // A deleted link ($link === null) has neither.
        ['endpointUrl' => $endpointUrl, 'endpoints' => $endpoints] = $this->resolveEndpointDisplay($log, $link);

        return $this->renderTemplate('influx/logs/view', [
            'config' => [
                'log'             => $presenter->presentLog($log),
                'items'           => $items,
                'itemTotal'       => $plugin->logs->itemCount($log, []),
                'perPage'         => self::ITEMS_PER_PAGE,
                'itemsUrl'        => UrlHelper::cpUrl("influx/logs/{$log->id}/items"),
                'itemUrlTemplate' => UrlHelper::cpUrl('influx/logs/items/__ID__'),
                'linkId'          => $link?->id,
                'linkName'        => $link?->name,
                'endpointUrl'     => $endpointUrl,
                'endpoints'       => $endpoints,
                'resourceHtml'    => $this->resolveResourceDisplay($log, $link),
                'isLive'          => in_array($log->status, ['running', 'pending'], true),
            ],
        ]);
    }

    /**
     * The "Resource" row for a single-element run: the element chip for the
     * resource the run was triggered for, or its `#id` when it has since been
     * deleted. Null for whole-feed runs, which have no single resource.
     */
    protected function resolveResourceDisplay(LogRecord $log, ?Link $link): ?string
    {
        if ($log->elementId === null) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById((int) $log->elementId, $link?->elementType);

        if (! $element) {
            return '<span class="light">#' . (int) $log->elementId . '</span>';
        }

        return (new ItemRowPresenter())->elementChip($element);
    }

    /**
     * Work out what to show in the log viewer's "Endpoint" row. Returns exactly
     * one of two populated shapes (see {@see actionView()} for the four cases):
     *
     *   - `endpointUrl` set, `endpoints` null — a single URL (single-element
     *     run's item-endpoint template, a site-scoped run, or an all-sites run
     *     on a link with no per-site endpoints);
     *   - `endpoints` a `[{site, url}]` list, `endpointUrl` null — an all-sites
     *     run on a link that HAS per-site endpoints (the base is never fetched,
     *     so no single URL is honest).
     *
     * Both null when the link has since been deleted.
     *
     * @return array{endpointUrl: ?string, endpoints: ?list<array{site: string, url: string}>}
     */
    protected function resolveEndpointDisplay(LogRecord $log, ?Link $link): array
    {
        if ($link === null) {
            return ['endpointUrl' => null, 'endpoints' => null];
        }

        // Single-element run: it fetched the item endpoint, not the feed —
        // the template (tokens unresolved) is the honest constant to show.
        if ($log->elementId !== null) {
            return ['endpointUrl' => $link->itemEndpoint, 'endpoints' => null];
        }

        // Site-scoped run: the one endpoint that site used (base as fallback).
        if ($log->siteHandle !== null) {
            $url = $link->endpointForSite($log->siteHandle) ?? $link->endpoint;

            return ['endpointUrl' => $url, 'endpoints' => null];
        }

        $siteHandles = $link->siteHandles();

        // All-sites run on a link with per-site endpoints: list every one — the
        // base was never fetched, so a single URL would misrepresent the run.
        if ($siteHandles !== []) {
            $endpoints = [];

            foreach ($siteHandles as $handle) {
                $url = $link->endpointForSite($handle) ?? $link->endpoint;

                if ($url !== null) {
                    $endpoints[] = ['site' => $handle, 'url' => $url];
                }
            }

            return ['endpointUrl' => null, 'endpoints' => $endpoints];
        }

        // All-sites run with no per-site endpoints: the base endpoint.
        return ['endpointUrl' => $link->endpoint, 'endpoints' => null];
    }

    /**
     * Drill-down for one stored log item. Re-runs the debug-view inspection
     * against the raw remote payload captured when the item was synced, so
     * the user can see per-field source/parsed/current values and which
     * mappings would (re-)apply if synced again. Pins to the item's own
     * `elementId` rather than re-deriving the element from the match value —
     * `$log->siteHandle` is null for element-triggered runs, so an unscoped
     * match-value lookup would be ambiguous whenever the same match value
     * exists on more than one element across sites.
     */
    public function actionItem(int $id): Response
    {
        $this->requireAcceptsJson();

        if (! ($item = LogItemRecord::findOne($id))) {
            throw new NotFoundHttpException("Log item #{$id} not found.");
        }

        if (! ($log = LogRecord::findOne($item->logId))) {
            throw new NotFoundHttpException("Log #{$item->logId} not found.");
        }

        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($log->linkHandle);

        if (! $link) {
            return $this->asJson([
                'row'     => null,
                'message' => Craft::t('influx', "Link '{handle}' no longer exists.", ['handle' => $log->linkHandle]),
            ]);
        }

        $raw = null;

        if ($item->payload) {
            $decoded = json_decode($item->payload, true);

            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if ($raw === null) {
            // No payload to re-inspect (swept missing-elements rows have none
            // by nature; older runs predate payload storage). Still return a
            // real row so the drill-down renders the headings + the stored
            // message as a normal band instead of a bare error state.
            return $this->asJson([
                'row' => [
                    'index'    => (int) $item->id,
                    'action'   => (string) $item->action,
                    'message'  => (string) ($item->message ?: Craft::t('influx', 'No stored payload for this item — drill-down was added after this run.')),
                    'mappings' => [],
                    'raw'      => null,
                ],
            ]);
        }

        $row = $plugin->debug->inspectItem($link, $raw, $log->siteHandle, $item->elementId !== null ? (int) $item->elementId : null, withParsedHtml: true);
        $row['index'] = (int) $item->id;
        $row['action'] = (string) $item->action;

        if ($item->message) {
            $row['message'] = (string) $item->message;
        }

        // Overlay the field errors captured at run time onto their mapping
        // rows — the stored error is authoritative (a dry-run re-inspection
        // can't reproduce a non-deterministic failure like an asset upload).
        $presenter = new LogPresenter();
        $row['mappings'] = $presenter->overlayFieldErrors($row['mappings'] ?? [], $presenter->fieldErrors($item->fieldErrors));

        // Overlay the run-time "changed" flags, likewise authoritative: the
        // inspection above is a dry run against the element's LIVE state, so a
        // successfully-updated item reads "no change" on every row. A null
        // column (rows without populate) resets the rows to the viewer's "?"
        // state instead of a misleading live value.
        $row['mappings'] = $presenter->overlayChangedFlags($row['mappings'], $item->changedFields);

        return $this->asJson(['row' => $row]);
    }

    /**
     * JSON endpoint backing the log-detail item list. Returns one page of
     * items (newest first, optionally filtered to a set of `actions`), the
     * total matching that filter, the refreshed counters/status, and whether
     * the run has settled. Used for both pager navigation and the live poll
     * (the client re-requests the page in view on an interval while running —
     * Craft's queue-runner pattern — rather than holding an SSE connection
     * and the PHP session lock open for the whole run).
     */
    public function actionItems(int $id): Response
    {
        $this->requireAcceptsJson();

        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        $page = $this->intQueryParam('page', 1, 1);
        // Single-select action filter (empty = all), plus a free-text search.
        // The param is `status`, not `action`: Craft reserves `action` for
        // controller-action routing, so `?action=…` would 404 the request.
        // A known action expands to its filter group so a per-site variant is
        // served alongside its base (deleted + deleted-for-site, etc.), matching
        // the grouped counter the filter is clicked from.
        $action = $this->stringQueryParam('status');
        $case = $action !== null ? ItemAction::tryFrom($action) : null;
        $actions = $case !== null ? $case->filterGroup() : ($action !== null ? [$action] : []);
        $search = $this->stringQueryParam('search');

        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;

        // The owning link's element type lets the presenter batch-load this
        // page's elements in one query (null when the link has been deleted).
        $link = $plugin->links->getLinkByHandle($log->linkHandle);
        $items = $presenter->presentItems($plugin->logs->itemPage($log, $actions, $offset, self::ITEMS_PER_PAGE, $search), $link?->elementType);

        return $this->asJson([
            'items'    => $items,
            'total'    => $plugin->logs->itemCount($log, $actions, $search),
            'counters' => $presenter->presentCounters($log),
            'done'     => ! in_array($log->status, ['running', 'pending'], true),
        ]);
    }

    /**
     * POST influx/logs/delete — drops one log row (its items cascade).
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');

        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        Influx::getInstance()->logs->delete($log);

        return $this->asSuccess(Craft::t('influx', 'Log #{id} deleted.', ['id' => $id]));
    }

    /**
     * POST influx/logs/clear — drops every log row.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Influx::getInstance()->logs->clear();

        return $this->asSuccess(Craft::t('influx', '{n} log entries cleared.', ['n' => $deleted]));
    }
}
