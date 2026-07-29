<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\web\DebugInspectorTranslations;
use GlueAgency\Influx\web\LinkBuilderTranslations;
use GlueAgency\Influx\web\LinkPresenter;
use GlueAgency\Influx\web\LogPresenter;
use GlueAgency\Influx\web\Vocabulary;
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

    /**
     * A link's `lastLogId` is nulled when that log is deleted, so a set one
     * always resolves and the per-link last-run logs — behind each row's status
     * dot and quick link — can be batch-loaded in one query keyed by id. The
     * persistent "when" is `link.lastRunAt`.
     */
    public function actionIndex(): Response
    {
        $plugin = Influx::getInstance();
        $links = $plugin->links->getAllLinks();

        $logIds = array_values(array_filter(array_map(static fn(Link $link) => $link->lastLogId, $links)));

        return $this->renderTemplate('influx/links/index', [
            'links'        => $links,
            'lastLogs'     => $plugin->logs->logsByIds($logIds),
            'presenter'    => new LinkPresenter(),
            'logPresenter' => new LogPresenter(),
            'readOnly'     => $this->readOnly(),
        ]);
    }

    /**
     * Dry-run inspector shell. Renders the site / offset / limit selector and a
     * results container the SPA fills from {@see actionDebugInspect}. Writes
     * nothing. Scoped by `?link=<handle>`, falling back to the first link
     * ({@see \GlueAgency\Influx\services\LinksService::getLinkByHandleOrFirst()})
     * so a bare `influx/debug` opens; what there is to choose from comes from
     * {@see LinkPresenter::debugOptions()}, which of it is selected from the
     * query string.
     */
    public function actionDebug(): Response
    {
        $plugin = Influx::getInstance();
        $allLinks = $plugin->links->getAllLinks();
        $link = $plugin->links->getLinkByHandleOrFirst($this->stringQueryParam('link'));

        if (! $link) {
            throw new NotFoundHttpException('No links available to debug.');
        }

        $options = (new LinkPresenter())->debugOptions($link, $allLinks);
        $siteHandles = array_column($options['sites'], 'handle');

        $this->registerAppTranslations(DebugInspectorTranslations::strings());

        return $this->renderTemplate('influx/links/debug', [
            'link'           => $link,
            'limit'          => $this->intQueryParam('limit', DebugService::DEFAULT_LIMIT, 1, 500),
            'sites'          => $options['sites'],
            'selectedSite'   => $this->oneOfQueryParam('site', $siteHandles, $siteHandles[0] ?? null),
            'offsetHandles'  => $options['offsetHandles'],
            'selectedOffset' => $this->oneOfQueryParam('offset', $options['offsetHandles']),
            'links'          => $options['links'],
            'linkHandle'     => $link->handle,
            'inspectUrl'     => UrlHelper::cpUrl('influx/debug/inspect', ['link' => $link->handle]),
            'vocabulary'     => Vocabulary::payload(),
        ]);
    }

    /**
     * JSON endpoint backing the debug page. Runs the dry-run inspection for the
     * selected site/offset/limit and returns the feed meta plus one row per
     * processed item in a single response. Strictly read-only — the inspector
     * only ever reads the first page, so there's nothing to stream.
     *
     * Rows come out JSON-serializable, so they pass straight through to the Vue
     * `DebugApp`; an `error` event (no target registered for the link) is
     * surfaced as a meta-level error instead.
     */
    public function actionDebugInspect(): Response
    {
        $this->requireAcceptsJson();

        $link = $this->linkOr404($this->stringQueryParam('link'));

        $limit = $this->intQueryParam('limit', DebugService::DEFAULT_LIMIT, 1, 500);
        $siteHandle = $this->oneOfQueryParam('site', $link->siteHandles());
        $offset = $this->oneOfQueryParam('offset', array_keys($link->offset ?? []));

        $meta = null;
        $items = [];

        foreach (Influx::getInstance()->debug->inspectSite($link, $siteHandle, $limit, $offset) as $event) {
            if ($event['type'] === 'meta') {
                $meta = $event['data'];
            } elseif ($event['type'] === 'item') {
                $items[] = $event['data'];
            } elseif ($event['type'] === 'error') {
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

        if ($id !== null) {
            $link = $link ?? $this->linkOr404($id);
            $title = trim($link->name) ?: Craft::t('influx', 'Edit link');
        } else {
            $link = $link ?? new Link([
                'elementType' => Entry::class,
                'processing'  => ProcessingAction::defaults(),
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
     *
     * It only renders a create form, but is gated as a mutating action
     * ({@see requireAccess()}), so it needs no read-only guard of its own. The
     * host link is passed blank to keep `data-id` off the mount — the SPA
     * bootstraps from `data-duplicate-of` instead.
     */
    public function actionDuplicate(int $id): Response
    {
        $this->linkOr404($id);

        return $this->builderScreen(Craft::t('influx', 'New link'), new Link(), $id);
    }

    /**
     * Render the LinkBuilder SPA host. The SPA owns the form and bootstraps its
     * own state over JSON — this only ships the shell (asset bundle, translated
     * strings, tabs) plus, via the host template, the link id to edit
     * (`data-id`) or the source id to duplicate (`data-duplicate-of`); a new
     * link carries neither.
     *
     * The tabs render into Craft's `#content-header` and Tabs.js toggles
     * `.hidden` on the element whose id matches the tab, so the Vue panes carry
     * those ids — see `_builder.twig`.
     *
     * The empty additional-buttons HTML ensures cpScreen renders its
     * `#action-buttons` header slot so the SPA can teleport its top-right
     * buttons (Fetch sample, Save) into it.
     *
     * Project-Config-backed config can't be saved in a read-only environment,
     * so Craft's standard read-only notice is shown there.
     */
    protected function builderScreen(string $title, Link $link, ?int $duplicateOf = null): Response
    {
        $this->registerAppTranslations(LinkBuilderTranslations::strings());

        $response = $this->asCpScreen()
            ->title($title)
            ->addCrumb(Craft::t('influx', 'Influx'), 'influx')
            ->addCrumb(Craft::t('influx', 'Links'), 'influx/links')
            ->tabs([
                'general'        => ['label' => Craft::t('influx', 'General'),        'url' => '#general'],
                'pagination'     => ['label' => Craft::t('influx', 'Pagination'),     'url' => '#pagination'],
                'mapping'        => ['label' => Craft::t('influx', 'Mapping'),        'url' => '#mapping'],
                'authentication' => ['label' => Craft::t('influx', 'Authentication'), 'url' => '#authentication'],
                'settings'       => ['label' => Craft::t('influx', 'Settings'),       'url' => '#settings'],
            ])
            ->contentTemplate('influx/links/_builder', ['link' => $link, 'duplicateOf' => $duplicateOf]);

        Compat::additionalButtonsHtml($response, '<div data-influx-actions-slot></div>');

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
        $this->requireJsonWrite();

        $uids = Craft::$app->getRequest()->getRequiredBodyParam('uids');

        Influx::getInstance()->links->saveOrder((array) $uids);

        return $this->asSuccess(Craft::t('influx', 'Link order saved.'));
    }
}
