<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\web\Controller;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\queue\jobs\SyncLinkJob;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP-side sync triggers.
 *
 *   POST influx/synchronization/link     — push a link run onto the queue
 *   POST influx/synchronization/element  — sync one element via its link (sync,
 *                                          so cooldown + UI feedback are immediate)
 */
class SynchronizationController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function actionLink(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-influx');

        $request = Craft::$app->getRequest();
        $handle = $request->getRequiredBodyParam('handle');
        $offset = $request->getBodyParam('offset');

        $plugin = Influx::getInstance();

        if (! ($link = $plugin->links->getLinkByHandle($handle))) {
            throw new NotFoundHttpException("Link '{$handle}' not found.");
        }

        Craft::$app->getQueue()->push(new SyncLinkJob([
            'linkHandle' => $link->handle,
            'offset'     => $offset,
            'trigger'    => 'cp',
        ]));

        return $this->asSuccess(Craft::t('influx', 'Sync queued for {link}.', [
            'link' => $link->name,
        ]));
    }

    public function actionElement(): ?Response
    {
        $this->requirePostRequest();

        $elementId = (int) Craft::$app->getRequest()->getRequiredBodyParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId);

        if (! $element) {
            throw new NotFoundHttpException("Element #{$elementId} not found.");
        }

        $this->requirePermission('accessPlugin-influx');

        $plugin = Influx::getInstance();
        $link = $plugin->links->findLinkForElement($element);

        if (! $link) {
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
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('influx', 'Element synced from {link}', [
            'link' => $link->name,
        ]));
    }
}
