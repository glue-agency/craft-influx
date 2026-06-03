<?php

namespace TDM\Influx\controllers;

use Craft;
use craft\web\Controller;
use TDM\Influx\Influx;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\records\LogItem as LogItemRecord;
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
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $perPage = 50;

        $query = LogRecord::find()->orderBy(['startedAt' => SORT_DESC]);
        $total = (clone $query)->count();
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
        $log = LogRecord::findOne($id)
            ?? throw new NotFoundHttpException("Log #{$id} not found.");

        $items = LogItemRecord::find()
            ->where(['logId' => $log->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        return $this->renderTemplate('influx/logs/view', [
            'log'   => $log,
            'items' => $items,
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
