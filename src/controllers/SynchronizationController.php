<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\queue\jobs\PrepareSyncJob;
use Throwable;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
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
    /**
     * Gated on the dedicated sync permission rather than the plugin-section
     * permission: the element "Sync from remote" button lives on the entry
     * edit page, so an entry editor must be able to trigger a sync without
     * Influx CP-section access. Admins always pass.
     */
    protected function requireAccess(Action $action): void
    {
        $this->requirePermission(Influx::PERMISSION_SYNC);
    }

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

        // Enqueue the orchestrator: it takes ONE pre-run backup (when the link
        // wants one), then fans out the per-site sync jobs. The request returns
        // immediately — no blocking on a DB dump — and a backup failure surfaces
        // as a failed log rather than a lost request.
        Craft::$app->getQueue()->push(new PrepareSyncJob([
            'linkHandle' => $link->handle,
            'offset'     => $offset,
            'site'       => $site,
            'trigger'    => SyncTrigger::CP->value,
        ]));

        $siteHandles = $link->siteHandles();

        $message = ($site === null && count($siteHandles) > 1)
            ? Craft::t('influx', 'Syncs queued for {n} sites.', ['n' => count($siteHandles)])
            : ($site
                ? Craft::t('influx', 'Sync queued for {link} ({site}).', ['link' => $link->name, 'site' => $site])
                : Craft::t('influx', 'Sync queued for {link}.', ['link' => $link->name]));

        return $this->asSuccess($message);
    }

    public function actionElement(): ?Response
    {
        $this->requirePostRequest();

        $elementId = (int) Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        // Load the element in the site the editor triggered the sync from, so a
        // per-site-endpoints link syncs only that site (see elementSyncSites).
        $siteHandle = Craft::$app->getRequest()->getBodyParam('site');
        $siteId = $siteHandle ? Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id : null;
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (! $element) {
            throw new NotFoundHttpException("Element #{$elementId} not found.");
        }

        // Even with the sync permission, never let a user push remote data into
        // an element they couldn't edit by hand.
        if (! Compat::canSaveElement($element)) {
            throw new ForbiddenHttpException("You don’t have permission to save element #{$elementId}.");
        }

        $plugin = Influx::getInstance();

        // An explicit link handle (the button/menu always sends one) pins the
        // sync to THAT link. We still require it to structurally target the
        // element — otherwise a caller could sync any link against an unrelated
        // element. Without a handle, fall back to the first link that targets
        // the element (preserves the pre-explicit-link single-link behaviour).
        $linkHandle = Craft::$app->getRequest()->getBodyParam('link');

        if ($linkHandle !== null && $linkHandle !== '') {
            $link = $plugin->links->getLinkByHandle($linkHandle);

            if (! $link) {
                throw new BadRequestHttpException("Link '{$linkHandle}' not found.");
            }

            $target = $plugin->targets->forLink($link);

            if (! $target || ! $target->targetsElement($link, $element)) {
                throw new BadRequestHttpException("Link '{$linkHandle}' doesn’t target element #{$elementId}.");
            }
        } else {
            $link = $plugin->links->findLinkForElement($element);

            if (! $link) {
                throw new BadRequestHttpException("No link targets element #{$elementId}.");
            }
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
