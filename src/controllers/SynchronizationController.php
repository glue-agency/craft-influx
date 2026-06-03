<?php

namespace TDM\Influx\controllers;

use Craft;
use craft\web\Controller;
use TDM\Influx\Influx;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP-side sync triggers.
 *
 *   POST influx/sync/<handle>            — kick off a full feed run
 *   POST influx/sync-item/<elementId>    — sync a single element from its feed
 */
class SynchronizationController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionFeed(string $handle): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-influx');

        $plugin = Influx::getInstance();
        $feed = $plugin->feeds->getByHandle($handle)
            ?? throw new NotFoundHttpException("Feed '{$handle}' not found.");

        $ago = Craft::$app->getRequest()->getBodyParam('ago');

        try {
            $log = $plugin->synchronization->syncFeed($feed, $ago, 'cp');
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('influx', '{n} items processed for {feed}', [
            'n'    => (int)$log->itemsSeen,
            'feed' => $feed->name,
        ]));
    }

    public function actionElement(int $elementId): Response
    {
        $this->requirePostRequest();

        $element = Craft::$app->getElements()->getElementById($elementId);
        if (!$element) {
            throw new NotFoundHttpException("Element #{$elementId} not found.");
        }

        $this->requirePermission('accessPlugin-influx');

        $plugin = Influx::getInstance();
        $feed = $plugin->feeds->findFeedForElement($element);
        if (!$feed) {
            throw new BadRequestHttpException("No feed claims element #{$elementId}.");
        }

        $remaining = $plugin->cooldown->remaining($feed, $element);
        if ($remaining > 0) {
            return $this->asFailure(
                Craft::t('influx', 'Cool-down active, try again in {n}s', ['n' => $remaining])
            );
        }

        try {
            $plugin->synchronization->syncElement($feed, $element);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('influx', 'Element synced from {feed}', [
            'feed' => $feed->name,
        ]));
    }
}
