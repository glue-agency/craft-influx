<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\services\EventStreamService;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use GlueAgency\Influx\web\LogPresenter;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends AbstractController
{
    public function actionIndex(): Response
    {
        $page = max(1, (int) Craft::$app->getRequest()->getQueryParam('page', 1));
        $perPage = 50;

        ['logs' => $logs, 'total' => $total] = Influx::getInstance()->logs->paginate($page, $perPage);

        // handle => id, so each row can link to its link's edit screen by id
        // (logs only store the handle); a deleted link is absent from the map.
        $linkIds = array_map(
            static fn($link) => $link->id,
            Influx::getInstance()->links->getAllLinks(),
        );

        return $this->renderTemplate('influx/logs/index', [
            'logs'    => $logs,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'linkIds' => $linkIds,
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
        // Extracted (not inlined into the array) so ECS doesn't align the
        // arrow-fn `=>` against the surrounding array's arrows.
        $items = array_map(fn($item) => $presenter->presentItem($item), $plugin->logs->itemsForLog($log));

        return $this->renderTemplate('influx/logs/view', [
            'config' => [
                'log'             => $presenter->presentLog($log),
                'items'           => $items,
                'streamUrl'       => UrlHelper::cpUrl("influx/logs/{$log->id}/stream"),
                'itemUrlTemplate' => UrlHelper::cpUrl('influx/logs/items/__ID__'),
                // id of the link this log belongs to (null if it was deleted),
                // for the "back to link" cross-link — logs only store the handle.
                'linkId' => $plugin->links->getLinkByHandle($log->linkHandle)?->id,
                'isLive' => in_array($log->status, ['running', 'pending'], true),
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

        return $this->asJson(['row' => $row]);
    }

    /**
     * SSE endpoint for live log updates. Streams per-item rows and counter
     * refreshes while the run is in `running` / `pending` status, then a
     * `done` sentinel once it settles. For finished logs it just emits the
     * final counters + done immediately.
     *
     * The SSE transport lives in {@see EventStreamService}; this only owns the
     * poll loop that produces the events.
     */
    public function actionStream(int $id): void
    {
        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        $lastId = (int) Craft::$app->getRequest()->getQueryParam('lastId', 0);
        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();

        $plugin->eventStream->run(function(EventStreamService $stream) use ($plugin, $presenter, $log, $lastId) {
            // Poll until the run finishes (or the client goes away). The first
            // pass also catches any items already written between page-load and
            // stream-connect.
            $deadline = time() + 600; // hard cap: 10 minutes

            while (true) {
                foreach ($plugin->logs->itemsForLogAfter($log, $lastId) as $item) {
                    $stream->send('item', $presenter->presentItem($item));
                    $lastId = (int) $item->id;
                }

                // Refresh the log row for live counters + status.
                $fresh = LogRecord::findOne($log->id);

                if ($fresh) {
                    $stream->send('counters', $presenter->presentCounters($fresh));
                    $log = $fresh;
                }

                if (! in_array($log->status, ['running', 'pending'], true)) {
                    break;
                }

                if ($stream->aborted() || time() > $deadline) {
                    break;
                }

                sleep(1);
            }

            $stream->send('done', ['status' => (string) $log->status]);
        });
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
