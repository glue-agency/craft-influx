<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use GlueAgency\Influx\web\assets\links\StylesAsset;
use GlueAgency\Influx\web\LinkPresenter;
use GlueAgency\Influx\web\LogPresenter;
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
        $viewActions = ['index', 'edit', 'debug', 'debug-inspect'];

        $this->requireAdmin(! in_array($action->id, $viewActions, true));
    }

    public function actionIndex(): Response
    {
        // The overview list reuses the plugin's server-rendered CP chrome (the
        // list-card + pill classes live in links.css, the CSS-only slice).
        Craft::$app->getView()->registerAssetBundle(StylesAsset::class);

        $links = Influx::getInstance()->links->getAllLinks();

        // The last-run log per link, for the status dot + quick link. Each
        // link's `lastLogId` is nulled when its log is deleted, so a set id
        // still resolves to a real log — batch-load them in one query, keyed
        // by id. The persistent "when" is `link.lastRunAt` (survives deletion).
        $logIds = array_values(array_filter(array_map(static fn($link) => $link->lastLogId, $links)));
        $lastLogs = $logIds
            ? LogRecord::find()->where(['id' => $logIds])->indexBy('id')->all()
            : [];

        return $this->renderTemplate('influx/links/index', [
            'links'        => $links,
            'lastLogs'     => $lastLogs,
            'presenter'    => new LinkPresenter(),
            'logPresenter' => new LogPresenter(),
            'readOnly'     => $this->readOnly(),
        ]);
    }

    /**
     * Dry-run inspector shell. Renders the site / offset / limit selector and a
     * results container the SPA fills from {@see actionDebugInspect}. Writes
     * nothing.
     */
    public function actionDebug(): Response
    {
        // Standalone inspector: the link is chosen by handle (?link=<handle>),
        // falling back to the first link so a bare influx/debug still opens.
        $allLinks = Influx::getInstance()->links->getAllLinks();
        $handle = $this->stringQueryParam('link');
        $link = ($handle !== null ? ($allLinks[$handle] ?? null) : null) ?: (reset($allLinks) ?: null);

        if (! $link) {
            throw new NotFoundHttpException('No links available to debug.');
        }

        // The debug inspector is a Vue app (DebugApp) — ship the full bundle.
        Craft::$app->getView()->registerAssetBundle(LinksAsset::class);

        $limit = $this->intQueryParam('limit', DebugService::DEFAULT_LIMIT, 1, 500);

        $siteHandles = $link->siteHandles();
        $requestedSite = $this->stringQueryParam('site');
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
        $requestedOffset = $this->stringQueryParam('offset');
        $selectedOffset = $requestedOffset !== null && in_array($requestedOffset, $offsetHandles, true)
            ? $requestedOffset
            : null;

        // Every link, for the toolbar's link switcher — changing it navigates
        // to that link's inspector (?link=<handle>).
        $linkOptions = array_values(array_map(static fn(Link $l): array => [
            'handle' => $l->handle,
            'name'   => $l->name,
            'url'    => UrlHelper::cpUrl('influx/debug', ['link' => $l->handle]),
        ], $allLinks));

        return $this->renderTemplate('influx/links/debug', [
            'link'           => $link,
            'limit'          => $limit,
            'sites'          => $sites,
            'selectedSite'   => $selectedSite,
            'offsetHandles'  => $offsetHandles,
            'selectedOffset' => $selectedOffset,
            'links'          => $linkOptions,
            'linkHandle'     => $link->handle,
            'inspectUrl'     => UrlHelper::cpUrl('influx/debug/inspect', ['link' => $link->handle]),
        ]);
    }

    /**
     * JSON endpoint backing the debug page. Runs the dry-run inspection for the
     * selected site/offset/limit and returns the feed meta plus one row per
     * processed item in a single response. Strictly read-only — the inspector
     * only ever reads the first page, so there's nothing to stream.
     */
    public function actionDebugInspect(): Response
    {
        $this->requireAcceptsJson();

        $handle = $this->stringQueryParam('link');

        if ($handle === null || ! ($link = Influx::getInstance()->links->getLinkByHandle($handle))) {
            throw new NotFoundHttpException('Link not found.');
        }

        $limit = $this->intQueryParam('limit', DebugService::DEFAULT_LIMIT, 1, 500);

        $siteHandle = $this->stringQueryParam('site');

        if ($siteHandle !== null && ! in_array($siteHandle, $link->siteHandles(), true)) {
            $siteHandle = null;
        }

        $offset = $this->stringQueryParam('offset');

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

        return $this->builderScreen($title, $link);
    }

    /**
     * Open the builder prefilled from an existing link, ready to save as a NEW
     * one — the source's config with a fresh identity (see
     * {@see \GlueAgency\Influx\services\LinkBuilderService::bootstrap()}, which
     * does the prefill from the `duplicateOf` query param the host template
     * carries). Nothing is written until the user hits Save, so they can rename
     * / adjust first. Reached from the overview's Duplicate action.
     */
    public function actionDuplicate(int $id): Response
    {
        // Renders a create form → gated as a mutating action (requireAccess
        // keeps 'duplicate' out of $viewActions), so no read-only guard here.
        if (! Influx::getInstance()->links->getLinkById($id)) {
            throw new NotFoundHttpException("Link {$id} not found.");
        }

        // A blank host link keeps `data-id` off the mount point; the SPA
        // bootstraps the prefilled copy from `data-duplicate-of` instead.
        return $this->builderScreen(Craft::t('influx', 'New link'), new Link(), $id);
    }

    /**
     * Render the LinkBuilder SPA host. The SPA owns the form and bootstraps its
     * own state over JSON — this only ships the shell (asset bundle, translated
     * strings, tabs) plus, via the host template, the link id to edit
     * (`data-id`) or the source id to duplicate (`data-duplicate-of`); a new
     * link carries neither.
     *
     * The empty additional-buttons HTML ensures cpScreen renders its
     * `#action-buttons` header slot so the SPA can teleport its top-right
     * buttons (Fetch sample, Save) into it.
     */
    protected function builderScreen(string $title, Link $link, ?int $duplicateOf = null): Response
    {
        $plugin = Influx::getInstance();
        $view = Craft::$app->getView();
        $view->registerAssetBundle(LinksAsset::class);
        $view->registerTranslations('influx', $plugin->linkBuilder->translatableStrings());

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
            ->contentTemplate('influx/links/_builder', ['link' => $link, 'duplicateOf' => $duplicateOf]);

        Compat::additionalButtonsHtml($response, '<div data-influx-actions-slot></div>');

        // A link's config is Project-Config-backed, so a read-only environment
        // can't save it — surface Craft's standard settings notice, the same one
        // its own Settings screens show.
        if ($this->readOnly()) {
            Compat::noticeHtml($response, Compat::readOnlyNoticeHtml());
        }

        return $response;
    }

    /**
     * Delete a link by UID. Serves both callers: the overview's per-row form
     * (regular POST → success flash + posted redirect) and the builder's
     * header menu (JSON → the SPA shows the notice and navigates itself).
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $uid = Craft::$app->getRequest()->getRequiredBodyParam('uid');

        if (! Influx::getInstance()->links->deleteLinkByUid($uid)) {
            return $this->asFailure(Craft::t('influx', 'Link not found.'));
        }

        return $this->asSuccess(Craft::t('influx', 'Link deleted.'));
    }

    /**
     * Persist a drag-to-sort reorder of the links overview. Receives the link
     * UIDs in their new order and writes the positions back through
     * {@see \GlueAgency\Influx\services\LinksService::saveOrder()} (Project
     * Config → DB). Mutating, so it needs `allowAdminChanges`.
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->assertWriteable();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('uids');

        Influx::getInstance()->links->saveOrder((array) $uids);

        return $this->asSuccess(Craft::t('influx', 'Link order saved.'));
    }
}
