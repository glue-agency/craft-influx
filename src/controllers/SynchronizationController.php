<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
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

    /**
     * The requested site is validated up front, for immediate feedback instead
     * of a later queue failure. Queueing itself returns immediately
     * ({@see \GlueAgency\Influx\services\SynchronizationService::queueSync()}),
     * so a pre-run backup failure lands as a failed log rather than blocking the
     * request.
     *
     * The flash message counts the scopes {@see \GlueAgency\Influx\models\Link::syncSiteHandles()}
     * resolves — the same rule the fan-out queues on — so it can't claim a
     * different number of runs than were actually pushed.
     */
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

        if ($site !== null && ! in_array($site, $link->siteHandles(), true)) {
            throw new BadRequestHttpException("Link '{$handle}' has no endpoint for site '{$site}'.");
        }

        $plugin->synchronization->queueSync($link, $offset, $site, SyncTrigger::CP);

        $queuedSites = $site !== null ? [$site] : $link->syncSiteHandles();

        $message = count($queuedSites) > 1
            ? Craft::t('influx', 'Syncs queued for {n} sites.', ['n' => count($queuedSites)])
            : ($site
                ? Craft::t('influx', 'Sync queued for {link} ({site}).', ['link' => $link->name, 'site' => $site])
                : Craft::t('influx', 'Sync queued for {link}.', ['link' => $link->name]));

        return $this->asSuccess($message);
    }

    /**
     * The element is loaded in the site the sync was triggered from, so a link
     * with per-site endpoints syncs only that site.
     *
     * Even with {@see Influx::PERMISSION_SYNC}, remote data is never pushed into
     * an element the user couldn't edit by hand. An explicit link handle (always
     * sent) pins the sync to THAT link and still requires it to target the
     * element, so a caller can't sync an unrelated one; without a handle, the
     * first link targeting the element is used.
     */
    public function actionElement(): ?Response
    {
        $this->requirePostRequest();

        $elementId = (int) Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        $siteHandle = Craft::$app->getRequest()->getBodyParam('site');
        $siteId = $siteHandle ? Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id : null;
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (! $element) {
            throw new NotFoundHttpException("Element #{$elementId} not found.");
        }

        if (! Compat::canSaveElement($element)) {
            throw new ForbiddenHttpException("You don’t have permission to save element #{$elementId}.");
        }

        $plugin = Influx::getInstance();

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
