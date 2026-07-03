<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use GlueAgency\Influx\enums\SyncTrigger;
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
class SynchronizationController extends AbstractController
{
    public function actionLink(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $handle = $request->getRequiredBodyParam('handle');
        $offset = $request->getBodyParam('offset');
        $site = $request->getBodyParam('site');

        $plugin = Influx::getInstance();

        if (! ($link = $plugin->links->getLinkByHandle($handle))) {
            throw new NotFoundHttpException("Link '{$handle}' not found.");
        }

        // Validate the requested site up front so the user gets immediate
        // feedback instead of a job that fails later in the queue.
        if ($site !== null && ! in_array($site, $link->siteHandles(), true)) {
            throw new BadRequestHttpException("Link '{$handle}' has no endpoint for site '{$site}'.");
        }

        $queue = Craft::$app->getQueue();
        $siteHandles = $link->siteHandles();

        // An all-sites trigger on a multi-endpoint link fans out to ONE job per
        // site, each scoped to (and logged for) its own site. A single-site
        // trigger, or a link with 0/1 site endpoints, pushes a single job.
        if ($site === null && count($siteHandles) > 1) {
            foreach ($siteHandles as $handle) {
                $queue->push(new SyncLinkJob([
                    'linkHandle' => $link->handle,
                    'offset'     => $offset,
                    'site'       => $handle,
                    'trigger'    => SyncTrigger::CP->value,
                ]));
            }

            return $this->asSuccess(Craft::t('influx', 'Syncs queued for {n} sites.', ['n' => count($siteHandles)]));
        }

        $queue->push(new SyncLinkJob([
            'linkHandle' => $link->handle,
            'offset'     => $offset,
            'site'       => $site,
            'trigger'    => SyncTrigger::CP->value,
        ]));

        $message = $site
            ? Craft::t('influx', 'Sync queued for {link} ({site}).', ['link' => $link->name, 'site' => $site])
            : Craft::t('influx', 'Sync queued for {link}.', ['link' => $link->name]);

        return $this->asSuccess($message);
    }

    public function actionElement(): ?Response
    {
        $this->requirePostRequest();

        $elementId = (int) Craft::$app->getRequest()->getRequiredBodyParam('elementId');
        $element = Craft::$app->getElements()->getElementById($elementId);

        if (! $element) {
            throw new NotFoundHttpException("Element #{$elementId} not found.");
        }

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
