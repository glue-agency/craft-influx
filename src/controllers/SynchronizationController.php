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
 *   POST influx/synchronization/link     — kick off a full link run
 *   POST influx/synchronization/element  — sync one element via its link
 */
class SynchronizationController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionLink(string $handle): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-influx');

        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($handle)
            ?? throw new NotFoundHttpException("Link '{$handle}' not found.");

        $ago = Craft::$app->getRequest()->getBodyParam('ago');

        try {
            $log = $plugin->synchronization->syncLink($link, $ago, 'cp');
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('influx', '{n} items processed for {link}', [
            'n'    => (int)$log->itemsSeen,
            'link' => $link->name,
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
        $link = $plugin->links->findLinkForElement($element);
        if (!$link) {
            throw new BadRequestHttpException("No link claims element #{$elementId}.");
        }

        $remaining = $plugin->cooldown->remaining($link, $element);
        if ($remaining > 0) {
            return $this->asFailure(
                Craft::t('influx', 'Cool-down active, try again in {n}s', ['n' => $remaining]),
            );
        }

        try {
            $plugin->synchronization->syncElement($link, $element);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('influx', 'Element synced from {link}', [
            'link' => $link->name,
        ]));
    }
}
