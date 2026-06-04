<?php

namespace TDM\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\web\Controller;
use TDM\Influx\controllers\support\LinkPostNormalizer;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\web\assets\links\LinksAsset;
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

    private bool $readOnly;

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
     * Dry-run inspector shell. Renders the site / ago / limit selector and a
     * results container that gets populated live via {@see actionDebugStream}.
     * Writes nothing.
     */
    public function actionDebug(string $handle): Response
    {
        $link = Influx::getInstance()->links->getLinkByHandle($handle)
            ?? throw new NotFoundHttpException("Link '{$handle}' not found.");

        $limit = (int)Craft::$app->getRequest()->getQueryParam('limit', \TDM\Influx\services\DebugService::DEFAULT_LIMIT);
        $limit = max(1, min($limit, 500));

        $siteHandles = $link->siteHandles();
        $requestedSite = Craft::$app->getRequest()->getQueryParam('site');
        $selectedSite = $requestedSite !== null && in_array($requestedSite, $siteHandles, true)
            ? $requestedSite
            : ($siteHandles[0] ?? null);

        $agoKeys = array_keys($link->ago ?? []);
        $requestedAgo = Craft::$app->getRequest()->getQueryParam('ago');
        $selectedAgo = $requestedAgo !== null && in_array($requestedAgo, $agoKeys, true)
            ? $requestedAgo
            : null;

        return $this->renderTemplate('influx/links/debug', [
            'link'         => $link,
            'limit'        => $limit,
            'siteHandles'  => $siteHandles,
            'selectedSite' => $selectedSite,
            'agoKeys'      => $agoKeys,
            'selectedAgo'  => $selectedAgo,
            'streamUrl'    => \craft\helpers\UrlHelper::cpUrl("influx/links/{$link->handle}/debug/stream"),
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
        $limit = max(1, min((int)$request->getQueryParam('limit', \TDM\Influx\services\DebugService::DEFAULT_LIMIT), 500));

        $siteHandle = $request->getQueryParam('site') ?: null;
        if ($siteHandle !== null && !in_array($siteHandle, $link->siteHandles(), true)) {
            $siteHandle = null;
        }

        $ago = $request->getQueryParam('ago') ?: null;
        if ($ago !== null && !isset($link->ago[$ago])) {
            $ago = null;
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
            foreach ($svc->streamSite($link, $siteHandle, $limit, $ago) as $event) {
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
                'processing'  => ['create', 'update'],
            ]);
            $title = Craft::t('influx', 'New link');
        }

        Craft::$app->getView()->registerAssetBundle(LinksAsset::class);

        $sectionEntryTypes  = $this->sectionEntryTypes();
        $mappableFields     = $this->mappableFieldsForLink($link);
        $mappableGroups     = $this->groupMappableFields($mappableFields);
        $matchFieldOptions  = $this->matchFieldOptions($mappableFields);

        $variables = [
            'link'   => $link,
            'isNew'  => $isNew,
            'readOnly' => $this->readOnly,
            'elementTypeOptions' => $this->elementTypeOptions(),
            'sectionOptions'     => $this->sectionOptions(),
            'sectionEntryTypes'  => $sectionEntryTypes,
            'siteOptions'        => $this->siteOptions(),
            'processingOptions'  => $this->processingOptions(),
            'authTypeOptions'    => $this->authTypeOptions(),
            'mappableFields'     => $mappableFields,
            'mappableGroups'     => $mappableGroups,
            'matchFieldOptions'  => $matchFieldOptions,
        ];

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
            ->contentTemplate('influx/links/_edit', $variables);

        if (!$this->readOnly) {
            $response
                ->action('influx/links/save')
                ->redirectUrl('influx/links')
                ->additionalButtonsHtml(
                    '<button type="button" class="btn" data-icon="download" id="influx-fetch-sample">'
                    . Craft::t('influx', 'Fetch sample')
                    . '</button>'
                )
                ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                    'redirect'    => 'influx/links/{handle}/edit',
                    'shortcut'    => true,
                    'retainScroll' => true,
                ]);

            if (!$isNew && $link->uid) {
                $response->addAltAction(Craft::t('app', 'Delete'), [
                    'action'              => 'influx/links/delete',
                    'destructive'         => true,
                    'confirm'             => Craft::t('influx', 'Are you sure you want to delete this link?'),
                    'redirect'            => 'influx/links',
                    'params'              => ['uid' => $link->uid],
                ]);
            }
        } else {
            $response->noticeHtml(Cp::readOnlyNoticeHtml());
        }

        return $response;
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Influx::getInstance();
        $request = Craft::$app->getRequest();

        $uid = $request->getBodyParam('uid') ?: null;
        $link = $uid
            ? $plugin->links->getLinkByUid($uid) ?? new Link()
            : new Link();

        (new LinkPostNormalizer())->apply($link, $request->getBodyParams());

        if (!$plugin->links->saveLink($link)) {
            return $this->asModelFailure(
                $link,
                Craft::t('influx', 'Couldn’t save link.'),
                'link',
            );
        }

        return $this->asModelSuccess(
            $link,
            Craft::t('influx', 'Link saved.'),
            'link',
            ['handle' => $link->handle],
        );
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

    /**
     * Inspect the configured endpoint and return rootNode / paginatorNode /
     * mapping suggestions for the CP "Fetch sample" button.
     */
    public function actionFetchSample(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $normalizer = new LinkPostNormalizer();

        $endpoint = $request->getBodyParam('endpoint');
        $link = new Link([
            'handle'      => 'sample',
            'name'        => 'sample',
            'elementType' => 'sample',
            'endpoint'    => ($endpoint === null || $endpoint === '') ? null : (string)$endpoint,
            'auth'        => $normalizer->auth($request->getBodyParam('auth') ?: []),
        ]);

        if (!$link->endpoint) {
            return $this->asFailure(Craft::t('influx', 'Set a list endpoint first.'));
        }

        try {
            $report = Influx::getInstance()->data->inspect($link);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'success' => true,
            'report'  => $report,
        ]);
    }

    // -- helpers --------------------------------------------------------

    private function elementTypeOptions(): array
    {
        $options = [];
        foreach (Influx::getInstance()->targets->all() as $fqcn => $target) {
            $label = ltrim($fqcn, '\\');
            $options[$label] = $label;
        }
        if (empty($options)) {
            $options[Entry::class] = Entry::class;
        }
        ksort($options);
        return $options;
    }

    private function sectionOptions(): array
    {
        $options = ['' => Craft::t('influx', '— Select —')];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[$section->handle] = $section->name;
        }
        return $options;
    }

    /**
     * Map of sectionHandle => [entryTypeHandle => entryTypeName] used by the
     * Entry-type dropdown which depends on the currently-selected section.
     */
    /**
     * Resolve the mappable fields for the current link by asking its target
     * adapter. Returns an empty list when no target handles the link yet
     * (e.g. fresh link with no element type selected).
     */
    private function mappableFieldsForLink(Link $link): array
    {
        if (!$link->elementType) {
            return [];
        }
        $target = Influx::getInstance()->targets->forLink($link);
        if (!$target) {
            return [];
        }
        return $target->getMappableFields($link);
    }

    /**
     * Group the flat mappable-fields list into the buckets the UI renders:
     * one per field-layout tab (and one for natives). Preserves the order
     * in which getMappableFields() returned the fields.
     *
     * @return list<array{label: string, fields: list<array>}>
     */
    private function groupMappableFields(array $mappableFields): array
    {
        $byLabel = [];
        foreach ($mappableFields as $field) {
            $label = $field['group'] ?? Craft::t('influx', 'Other');
            if (!isset($byLabel[$label])) {
                $byLabel[$label] = ['label' => $label, 'fields' => []];
            }
            $byLabel[$label]['fields'][] = $field;
        }
        return array_values($byLabel);
    }

    /**
     * Build the options for the Match-attribute dropdown. Driven by the
     * target adapter's mappable fields so the user can only pair the match
     * key with a real field on the element.
     */
    private function matchFieldOptions(array $mappableFields): array
    {
        $options = ['' => Craft::t('influx', '— Select a field —')];
        foreach ($mappableFields as $f) {
            $options[$f['handle']] = $f['name'] . ' (' . $f['handle'] . ')';
        }
        return $options;
    }

    private function sectionEntryTypes(): array
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $types = [];
            foreach ($section->getEntryTypes() as $type) {
                $types[$type->handle] = $type->name;
            }
            $out[$section->handle] = $types;
        }
        return $out;
    }

    private function siteOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[$site->handle] = $site->name;
        }
        return $options;
    }

    private function authTypeOptions(): array
    {
        return [
            ''            => Craft::t('influx', 'None'),
            'bearer'      => Craft::t('influx', 'Bearer token'),
            'custom'      => Craft::t('influx', 'Custom header'),
            'querystring' => Craft::t('influx', 'Query string parameter'),
        ];
    }

    private function processingOptions(): array
    {
        return [
            'create'           => Craft::t('influx', 'Create — make new elements'),
            'update'           => Craft::t('influx', 'Update — change existing elements'),
            'disable'          => Craft::t('influx', 'Disable — soft-disable elements removed from the link'),
            'delete'           => Craft::t('influx', 'Delete — hard-delete elements removed from the link'),
            'delete-for-site'  => Craft::t('influx', 'Delete for site — remove the localized row only'),
        ];
    }

    private function lastRunPerLink(): array
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
