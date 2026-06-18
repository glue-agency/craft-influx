<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use Throwable;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Influx links — CP browser and editor.
 *
 * Links are stored in Project Config. The CP edit form writes back to PC
 * (when `allowAdminChanges` is on) the same way Craft 5 manages Sections,
 * Entry Types, Volumes, etc.
 */
class LinksController extends AbstractController
{
    /**
     * Links live in Project Config, so they're admin territory rather than a
     * plugin permission. View actions still work in a read-only environment
     * (requireAdmin(false)); mutating actions also require allowAdminChanges
     * (requireAdmin()).
     */
    protected function requireAccess(Action $action): void
    {
        $viewActions = ['index', 'view', 'edit', 'debug', 'debug-stream'];

        $this->requireAdmin(! in_array($action->id, $viewActions, true));
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('influx/links/index', [
            'links'    => Influx::getInstance()->links->getAllLinks(),
            'lastRuns' => Influx::getInstance()->logs->lastRunPerLink(),
            'readOnly' => $this->readOnly(),
        ]);
    }

    public function actionView(int $id): Response
    {
        if (! $this->readOnly()) {
            return $this->redirect("influx/links/{$id}/edit");
        }

        if (! ($link = Influx::getInstance()->links->getLinkById($id))) {
            throw new NotFoundHttpException("Link {$id} not found.");
        }

        $recentLogs = Influx::getInstance()->logs->recentForLink($link->handle, 20);

        return $this->renderTemplate('influx/links/view', [
            'link'       => $link,
            'recentLogs' => $recentLogs,
            'readOnly'   => $this->readOnly(),
        ]);
    }

    /**
     * Dry-run inspector shell. Renders the site / offset / limit selector and a
     * results container the SPA fills from {@see actionDebugInspect}. Writes
     * nothing.
     */
    public function actionDebug(int $id): Response
    {
        if (! ($link = Influx::getInstance()->links->getLinkById($id))) {
            throw new NotFoundHttpException("Link {$id} not found.");
        }

        // The debug inspector is a Vue app (DebugApp) — ship the full bundle.
        Craft::$app->getView()->registerAssetBundle(LinksAsset::class);

        $limit = (int) Craft::$app->getRequest()->getQueryParam('limit', DebugService::DEFAULT_LIMIT);
        $limit = max(1, min($limit, 500));

        $siteHandles = $link->siteHandles();
        $requestedSite = Craft::$app->getRequest()->getQueryParam('site');
        $selectedSite = $requestedSite !== null && in_array($requestedSite, $siteHandles, true)
            ? $requestedSite
            : ($siteHandles[0] ?? null);

        // Friendly site names for the dropdown labels; the option value stays
        // the handle (what the stream and $selectedSite work with).
        $sites = array_map(static fn(string $handle): array => [
            'handle' => $handle,
            'name'   => Craft::$app->getSites()->getSiteByHandle($handle)?->name ?? $handle,
        ], $siteHandles);

        $offsetHandles = array_keys($link->offset ?? []);
        $requestedOffset = Craft::$app->getRequest()->getQueryParam('offset');
        $selectedOffset = $requestedOffset !== null && in_array($requestedOffset, $offsetHandles, true)
            ? $requestedOffset
            : null;

        return $this->renderTemplate('influx/links/debug', [
            'link'           => $link,
            'limit'          => $limit,
            'sites'          => $sites,
            'selectedSite'   => $selectedSite,
            'offsetHandles'  => $offsetHandles,
            'selectedOffset' => $selectedOffset,
            'processing'     => array_values($link->processing ?? []),
            'inspectUrl'     => UrlHelper::cpUrl("influx/links/{$link->id}/debug/inspect"),
        ]);
    }

    /**
     * JSON endpoint backing the debug page. Runs the dry-run inspection for the
     * selected site/offset/limit and returns the feed meta plus one row per
     * processed item in a single response. Strictly read-only — the inspector
     * only ever reads the first page, so there's nothing to stream.
     */
    public function actionDebugInspect(int $id): Response
    {
        $this->requireAcceptsJson();

        if (! ($link = Influx::getInstance()->links->getLinkById($id))) {
            throw new NotFoundHttpException("Link {$id} not found.");
        }

        $request = Craft::$app->getRequest();
        $limit = max(1, min((int) $request->getQueryParam('limit', DebugService::DEFAULT_LIMIT), 500));

        $siteHandle = $request->getQueryParam('site') ?: null;

        if ($siteHandle !== null && ! in_array($siteHandle, $link->siteHandles(), true)) {
            $siteHandle = null;
        }

        $offset = $request->getQueryParam('offset') ?: null;

        if ($offset !== null && ! isset($link->offset[$offset])) {
            $offset = null;
        }

        $meta = null;
        $items = [];

        // Each row is already JSON-serializable (the Vue DebugApp renders it).
        foreach (Influx::getInstance()->debug->streamSite($link, $siteHandle, $limit, $offset) as $event) {
            if ($event['type'] === 'meta') {
                $meta = $event['data'];
            } elseif ($event['type'] === 'item') {
                $items[] = $event['data'];
            } elseif ($event['type'] === 'error') {
                // No target registered — surface as a meta-level error.
                $meta = ['error' => $event['data']['message'] ?? Craft::t('influx', 'Inspection failed.')];
            }
        }

        return $this->asJson(['meta' => $meta, 'items' => $items]);
    }

    public function actionEdit(?int $id = null, ?Link $link = null): Response
    {
        if ($id === null) {
            $this->assertWriteable();
        }

        $plugin = Influx::getInstance();
        $isNew = ($id === null);

        if ($id !== null) {
            if ($link === null) {
                if (! ($link = $plugin->links->getLinkById($id))) {
                    throw new NotFoundHttpException("Link {$id} not found.");
                }
            }
            $title = trim($link->name) ?: Craft::t('influx', 'Edit link');
        } else {
            $link = $link ?? new Link([
                'elementType' => Entry::class,
                'processing'  => [Link::PROCESSING_CREATE, Link::PROCESSING_UPDATE],
            ]);
            $title = Craft::t('influx', 'New link');
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(LinksAsset::class);

        // Populate `Craft.translations.influx` for the SPA. Without this,
        // every `this.$t('…')` in the Vue layer would fall through to the
        // English source string — fine for default deployments, but blocks
        // any locale-specific translations from being applied. The list
        // mirrors what the templates wrap (see LinkBuilderService).
        $view->registerTranslations('influx', $plugin->linkBuilder->translatableStrings());

        // The SPA owns the form. We only ship the host template + the
        // link id (if any). Save is wired through the JSON endpoint inside
        // the Vue layer — Craft's standard cpScreen form submit is bypassed.
        //
        // The empty additional-buttons HTML ensures cpScreen renders its
        // `#action-buttons` header slot so the SPA can teleport its
        // top-right buttons (Fetch sample, Save) into it.
        $response = $this->asCpScreen()
            ->title($title)
            ->addCrumb(Craft::t('influx', 'Influx'), 'influx')
            ->addCrumb(Craft::t('influx', 'Links'), 'influx/links')
            // Tabs render into Craft's standard #content-header slot.
            // Craft's Tabs.js wires the activation: clicking #pagination
            // toggles `.hidden` on the element with that id (and so on).
            // Our Vue content panes carry those ids — see _builder.twig.
            ->tabs([
                'general'        => ['label' => Craft::t('influx', 'General'),        'url' => '#general'],
                'pagination'     => ['label' => Craft::t('influx', 'Pagination'),     'url' => '#pagination'],
                'mapping'        => ['label' => Craft::t('influx', 'Mapping'),        'url' => '#mapping'],
                'authentication' => ['label' => Craft::t('influx', 'Authentication'), 'url' => '#authentication'],
                'settings'       => ['label' => Craft::t('influx', 'Settings'),       'url' => '#settings'],
            ])
            ->contentTemplate('influx/links/_builder', ['link' => $link]);

        Compat::additionalButtonsHtml($response, '<div data-influx-actions-slot></div>');

        if ($this->readOnly()) {
            Compat::noticeHtml($response, Compat::readOnlyNoticeHtml());
        } elseif (! $isNew && $link->uid) {
            $response->addAltAction(Craft::t('app', 'Delete'), [
                'action'      => 'influx/links/delete',
                'destructive' => true,
                'confirm'     => Craft::t('influx', 'Are you sure you want to delete this link?'),
                'redirect'    => 'influx/links',
                'params'      => ['uid' => $link->uid],
            ]);
        }

        return $response;
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $uid = Craft::$app->getRequest()->getRequiredBodyParam('uid');

        if (! Influx::getInstance()->links->deleteLinkByUid($uid)) {
            return $this->asFailure(Craft::t('influx', 'Link not found.'));
        }

        return $this->asSuccess(Craft::t('influx', 'Link deleted.'));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceHandle = $request->getRequiredBodyParam('handle');
        $newHandle = $request->getRequiredBodyParam('newHandle');
        $newName = $request->getBodyParam('newName');

        try {
            $link = Influx::getInstance()->links->duplicateLink($sourceHandle, $newHandle, $newName);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->redirect("influx/links/{$link->id}/edit");
    }
}
