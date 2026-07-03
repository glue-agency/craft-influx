<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use GlueAgency\Influx\web\ItemRowPresenter;
use GlueAgency\Influx\web\LogPresenter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends AbstractController
{
    public const ITEMS_PER_PAGE = 25;

    public function actionIndex(): Response
    {
        $page = $this->intQueryParam('page', 1, 1);
        $perPage = 50;

        $plugin = Influx::getInstance();

        ['logs' => $logs, 'total' => $total] = $plugin->logs->paginate($page, $perPage);

        // handle => id / handle => name, so each row can link to its link's
        // edit screen by id and show its friendly name (logs only store the
        // handle); a deleted link is absent from both maps.
        $links = $plugin->links->getAllLinks();
        $linkIds = array_map(static fn($link)   => $link->id, $links);
        $linkNames = array_map(static fn($link) => $link->name, $links);

        return $this->renderTemplate('influx/logs/index', [
            'logs'      => $logs,
            'page'      => $page,
            'perPage'   => $perPage,
            'total'     => $total,
            'linkIds'   => $linkIds,
            'linkNames' => $linkNames,
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
     * resource the run was triggered for, or a "(gone)" note when it has since
     * been deleted. Null for whole-feed runs, which have no single resource.
     */
    protected function resolveResourceDisplay(LogRecord $log, ?Link $link): ?string
    {
        if ($log->elementId === null) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById((int) $log->elementId, $link?->elementType);

        if (! $element) {
            return '<span class="light">#' . (int) $log->elementId . ' (gone)</span>';
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
     * mappings would (re-)apply if synced again.
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

        $row = $plugin->debug->inspectItem($link, $raw, $log->siteHandle);
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
        $action = $this->stringQueryParam('status');
        $actions = $action !== null ? [$action] : [];
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
