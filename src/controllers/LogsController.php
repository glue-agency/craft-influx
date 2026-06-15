<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-influx');

        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $page = max(1, (int) $request->getQueryParam('page', 1));
        $perPage = 50;

        $query = LogRecord::find()->orderBy(['startedAt' => SORT_DESC]);
        $total = $query->count();
        $logs = $query->offset(($page - 1) * $perPage)->limit($perPage)->all();

        return $this->renderTemplate('influx/logs/index', [
            'logs'    => $logs,
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
        ]);
    }

    public function actionView(int $id): Response
    {
        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        $items = LogItemRecord::find()
            ->where(['logId' => $log->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $this->renderTemplate('influx/logs/view', [
            'log'             => $log,
            'items'           => $items,
            'streamUrl'       => UrlHelper::cpUrl("influx/logs/{$log->id}/stream"),
            'itemUrlTemplate' => UrlHelper::cpUrl('influx/logs/items/__ID__'),
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
                'html' => '<div class="influx-debug-item"><p class="error">'
                    . Craft::t('influx', "Link '{handle}' no longer exists.", ['handle' => $log->linkHandle])
                    . '</p></div>',
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
                'html' => '<div class="influx-debug-item"><p class="light">'
                    . Craft::t('influx', 'No stored payload for this item — drill-down was added after this run.')
                    . '</p></div>',
            ]);
        }

        $siteId = $log->siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($log->siteHandle)?->id)
            : null;

        $row = $plugin->debug->inspectItem($link, $raw, $siteId);
        $row['index'] = (int) $item->id;
        $row['action'] = (string) $item->action;

        if ($item->message) {
            $row['message'] = (string) $item->message;
        }

        $html = Craft::$app->getView()->renderTemplate('influx/links/_debug-item', [
            'row' => $row,
        ]);

        return $this->asJson(['html' => $html]);
    }

    /**
     * SSE endpoint for live log updates. Streams per-item rows and counter
     * refreshes while the run is in `running` / `pending` status, then a
     * `done` sentinel once it settles. For finished logs it just emits the
     * final counters + done immediately.
     *
     * Bypasses Yii's normal response pipeline (same shape as the debug stream
     * on the link side) so each item can flush as soon as it lands in the DB.
     */
    public function actionStream(int $id): void
    {
        if (! ($log = LogRecord::findOne($id))) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        $request = Craft::$app->getRequest();
        $lastId = (int) $request->getQueryParam('lastId', 0);

        // Strip any output buffers Yii / PHP may have stacked, then take
        // exclusive control of the response.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        echo ": " . str_repeat(' ', 2048) . "\n\n";
        @flush();

        $emit = function(string $event, array $data): void {
            echo "event: {$event}\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        };

        $renderItem = function(LogItemRecord $item): array {
            $elementHtml = null;

            if ($item->elementId) {
                $el = Craft::$app->getElements()->getElementById($item->elementId);

                if ($el) {
                    $elementHtml = Compat::elementChipHtml($el, ['hyperlink' => true]);
                } else {
                    $elementHtml = '<span class="light">#' . $item->elementId . ' (gone)</span>';
                }
            }

            return [
                'id'          => (int) $item->id,
                'action'      => (string) $item->action,
                'matchValue'  => (string) ($item->matchValue ?? ''),
                'message'     => (string) ($item->message ?? ''),
                'elementHtml' => $elementHtml,
            ];
        };

        try {
            // Poll until the run finishes (or the client goes away). The first
            // pass also catches any items already written between page-load and
            // stream-connect.
            $deadline = time() + 600; // hard cap: 10 minutes

            while (true) {
                $newItems = LogItemRecord::find()
                    ->where(['logId' => $log->id])
                    ->andWhere(['>', 'id', $lastId])
                    ->orderBy(['id' => SORT_ASC])
                    ->all();

                foreach ($newItems as $item) {
                    $emit('item', $renderItem($item));
                    $lastId = (int) $item->id;
                }

                // Refresh the log row for live counters + status.
                $fresh = LogRecord::findOne($log->id);

                if ($fresh) {
                    $emit('counters', [
                        'status'         => (string) $fresh->status,
                        'itemsSeen'      => (int) $fresh->itemsSeen,
                        'itemsCreated'   => (int) $fresh->itemsCreated,
                        'itemsUpdated'   => (int) $fresh->itemsUpdated,
                        'itemsUnchanged' => (int) $fresh->itemsUnchanged,
                        'itemsSkipped'   => (int) $fresh->itemsSkipped,
                        'itemsDeleted'   => (int) $fresh->itemsDeleted,
                        'finishedAt'     => $fresh->finishedAt,
                        'error'          => $fresh->error,
                    ]);
                    $log = $fresh;
                }

                if (! in_array($log->status, ['running', 'pending'], true)) {
                    break;
                }

                if (connection_aborted() || time() > $deadline) {
                    break;
                }

                sleep(1);
            }

            $emit('done', ['status' => (string) $log->status]);
        } catch (Throwable $e) {
            $emit('error', ['message' => $e->getMessage()]);
        }

        Craft::$app->end();
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
