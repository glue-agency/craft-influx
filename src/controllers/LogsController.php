<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use GlueAgency\Influx\web\LogPresenter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends AbstractController
{
    public const ITEMS_PER_PAGE = 25;

    public function actionIndex(): Response
    {
        $page = max(1, (int) Craft::$app->getRequest()->getQueryParam('page', 1));
        $perPage = 50;

        ['logs' => $logs, 'total' => $total] = Influx::getInstance()->logs->paginate($page, $perPage);

        // handle => id / handle => name, so each row can link to its link's
        // edit screen by id and show its friendly name (logs only store the
        // handle); a deleted link is absent from both maps.
        $links = Influx::getInstance()->links->getAllLinks();
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
        // Only the first page rides along in the bootstrap — the rest is paged
        // in from actionItems(), so a huge run doesn't ship as one JSON blob.
        // Extracted (not inlined) so ECS doesn't align the arrow-fn `=>`.
        $items = array_map(fn($item) => $presenter->presentItem($item), $plugin->logs->itemPage($log, [], 0, self::ITEMS_PER_PAGE));

        // The link this log belongs to (null if it has since been deleted) —
        // logs only store the handle, so its id + name ride along in the config
        // for the header's "back to link" cross-link.
        $link = $plugin->links->getLinkByHandle($log->linkHandle);

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
                'isLive'          => in_array($log->status, ['running', 'pending'], true),
            ],
        ]);
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
            return $this->asJson([
                'row'     => null,
                'message' => Craft::t('influx', 'No stored payload for this item — drill-down was added after this run.'),
            ]);
        }

        $row = $plugin->debug->inspectItem($link, $raw, $log->siteHandle);
        $row['index'] = (int) $item->id;
        $row['action'] = (string) $item->action;

        if ($item->message) {
            $row['message'] = (string) $item->message;
        }

        // Overlay the field errors captured at run time onto their rows — a
        // dry-run re-inspection can't reproduce a non-deterministic failure
        // (e.g. an asset upload), so the stored error is authoritative.
        $fieldErrors = $item->fieldErrors ? json_decode($item->fieldErrors, true) : null;

        if (is_array($fieldErrors) && ! empty($row['mappings'])) {
            foreach ($row['mappings'] as &$mapping) {
                $handle = $mapping['handle'] ?? null;

                if ($handle !== null && isset($fieldErrors[$handle])) {
                    $mapping['error'] = $fieldErrors[$handle];
                }
            }
            unset($mapping);
        }

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

        $request = Craft::$app->getRequest();
        $page = max(1, (int) $request->getQueryParam('page', 1));
        $actions = array_values(array_filter((array) $request->getQueryParam('actions', [])));

        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;
        // Extracted (not inlined) so ECS doesn't align the arrow-fn `=>`.
        $items = array_map(fn($item) => $presenter->presentItem($item), $plugin->logs->itemPage($log, $actions, $offset, self::ITEMS_PER_PAGE));

        return $this->asJson([
            'items'    => $items,
            'total'    => $plugin->logs->itemCount($log, $actions),
            'counters' => $presenter->presentCounters($log),
            'done'     => ! in_array($log->status, ['running', 'pending'], true),
        ]);
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
