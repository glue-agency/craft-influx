<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\web\Controller;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\web\assets\links\LinksAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Influx links — CP browser and editor.
 *
 * Links are stored in Project Config. The CP edit form writes back to PC
 * (when `allowAdminChanges` is on) the same way Craft 5 manages Sections,
 * Entry Types, Volumes, etc.
 */
class LinksController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    protected bool $readOnly;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $viewActions = ['index', 'view', 'edit', 'debug', 'debug-stream'];
        if (in_array($action->id, $viewActions, true)) {
            $this->requireAdmin(false);
        } else {
            $this->requireAdmin();
        }

        $this->readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('influx/links/index', [
            'links'    => Influx::getInstance()->links->getAllLinks(),
            'lastRuns' => $this->lastRunPerLink(),
            'readOnly' => $this->readOnly,
        ]);
    }

    public function actionView(string $handle): Response
    {
        if (!$this->readOnly) {
            return $this->redirect("influx/links/{$handle}/edit");
        }

        $link = Influx::getInstance()->links->getLinkByHandle($handle)
            ?? throw new NotFoundHttpException("Link '{$handle}' not found.");

        $recentLogs = LogRecord::find()
            ->where(['linkHandle' => $handle])
            ->orderBy(['startedAt' => SORT_DESC])
            ->limit(20)
            ->all();

        return $this->renderTemplate('influx/links/view', [
            'link'       => $link,
            'recentLogs' => $recentLogs,
            'readOnly'   => $this->readOnly,
        ]);
    }

    /**
     * Dry-run inspector shell. Renders the site / offset / limit selector and a
     * results container that gets populated live via {@see actionDebugStream}.
     * Writes nothing.
     */
    public function actionDebug(string $handle): Response
    {
        $link = Influx::getInstance()->links->getLinkByHandle($handle)
            ?? throw new NotFoundHttpException("Link '{$handle}' not found.");

        $limit = (int)Craft::$app->getRequest()->getQueryParam('limit', \GlueAgency\Influx\services\DebugService::DEFAULT_LIMIT);
        $limit = max(1, min($limit, 500));

        $siteHandles = $link->siteHandles();
        $requestedSite = Craft::$app->getRequest()->getQueryParam('site');
        $selectedSite = $requestedSite !== null && in_array($requestedSite, $siteHandles, true)
            ? $requestedSite
            : ($siteHandles[0] ?? null);

        $offsetKeys = array_keys($link->offset ?? []);
        $requestedOffset = Craft::$app->getRequest()->getQueryParam('offset');
        $selectedOffset = $requestedOffset !== null && in_array($requestedOffset, $offsetKeys, true)
            ? $requestedOffset
            : null;

        return $this->renderTemplate('influx/links/debug', [
            'link'           => $link,
            'limit'          => $limit,
            'siteHandles'    => $siteHandles,
            'selectedSite'   => $selectedSite,
            'offsetKeys'     => $offsetKeys,
            'selectedOffset' => $selectedOffset,
            'streamUrl'      => \craft\helpers\UrlHelper::cpUrl("influx/links/{$link->handle}/debug/stream"),
        ]);
    }

    /**
     * SSE endpoint backing the debug page. Streams a `meta` event with site
     * metadata, then one `item` event per processed item, then a `done`
     * sentinel. Strictly read-only.
     *
     * Bypasses Yii's normal response pipeline and writes the event stream
     * directly so each item can flush as soon as it's processed.
     */
    public function actionDebugStream(string $handle): void
    {
        $link = Influx::getInstance()->links->getLinkByHandle($handle)
            ?? throw new NotFoundHttpException("Link '{$handle}' not found.");

        $request = Craft::$app->getRequest();
        $limit = max(1, min((int)$request->getQueryParam('limit', \GlueAgency\Influx\services\DebugService::DEFAULT_LIMIT), 500));

        $siteHandle = $request->getQueryParam('site') ?: null;
        if ($siteHandle !== null && !in_array($siteHandle, $link->siteHandles(), true)) {
            $siteHandle = null;
        }

        $offset = $request->getQueryParam('offset') ?: null;
        if ($offset !== null && !isset($link->offset[$offset])) {
            $offset = null;
        }

        // Strip any output buffers Yii / PHP may have stacked, then take
        // exclusive control of the response.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        // Pad so proxies that buffer until they see N bytes start forwarding.
        echo ": " . str_repeat(' ', 2048) . "\n\n";
        @flush();

        $view = Craft::$app->getView();
        $svc = Influx::getInstance()->debug;

        try {
            foreach ($svc->streamSite($link, $siteHandle, $limit, $offset) as $event) {
                if ($event['type'] === 'item') {
                    $html = $view->renderTemplate(
                        'influx/links/_debug-item',
                        ['row' => $event['data']],
                        \craft\web\View::TEMPLATE_MODE_CP,
                    );
                    $payload = ['index' => $event['data']['index'] ?? null, 'html' => $html];
                } else {
                    $payload = $event['data'];
                }

                echo "event: {$event['type']}\n";
                echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();

                if (connection_aborted()) {
                    break;
                }
            }

            echo "event: done\ndata: {}\n\n";
            @flush();
        } catch (\Throwable $e) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['message' => $e->getMessage()]) . "\n\n";
            @flush();
        }

        Craft::$app->end();
    }

    public function actionEdit(?string $handle = null, ?Link $link = null): Response
    {
        if ($handle === null && $this->readOnly) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }

        $plugin = Influx::getInstance();
        $isNew = ($handle === null);

        if ($handle !== null) {
            if ($link === null) {
                $link = $plugin->links->getLinkByHandle($handle)
                    ?? throw new NotFoundHttpException("Link '{$handle}' not found.");
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
        // handle (if any). Save is wired through the JSON endpoint inside
        // the Vue layer — Craft's standard cpScreen form submit is bypassed.
        //
        // The empty `additionalButtonsHtml` ensures cpScreen renders its
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
            ->additionalButtonsHtml('<div data-influx-actions-slot></div>')
            ->contentTemplate('influx/links/_builder', ['link' => $link]);

        if ($this->readOnly) {
            $response->noticeHtml(Cp::readOnlyNoticeHtml());
        } elseif (!$isNew && $link->uid) {
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

        $uid = (string)Craft::$app->getRequest()->getRequiredBodyParam('uid');

        if (!Influx::getInstance()->links->deleteLinkByUid($uid)) {
            return $this->asFailure(Craft::t('influx', 'Link not found.'));
        }

        return $this->asSuccess(Craft::t('influx', 'Link deleted.'));
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceHandle = (string)$request->getRequiredBodyParam('handle');
        $newHandle    = (string)$request->getRequiredBodyParam('newHandle');
        $newName      = $request->getBodyParam('newName');

        try {
            $link = Influx::getInstance()->links->duplicateLink($sourceHandle, $newHandle, $newName);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->redirect("influx/links/{$link->handle}/edit");
    }

    protected function lastRunPerLink(): array
    {
        $out = [];
        $logs = LogRecord::find()
            ->orderBy(['startedAt' => SORT_DESC])
            ->all();
        foreach ($logs as $log) {
            if (!isset($out[$log->linkHandle])) {
                $out[$log->linkHandle] = $log;
            }
        }
        return $out;
    }
}
