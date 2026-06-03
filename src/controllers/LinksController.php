<?php

namespace TDM\Influx\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\web\Controller;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;
use TDM\Influx\records\Log as LogRecord;
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

        $viewActions = ['index', 'view', 'edit'];
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

        $variables = [
            'link'   => $link,
            'isNew'  => $isNew,
            'readOnly' => $this->readOnly,
            'elementTypeOptions' => $this->elementTypeOptions(),
            'sectionOptions'     => $this->sectionOptions(),
            'mappingTypeOptions' => $this->mappingTypeOptions(),
            'siteOptions'        => $this->siteOptions(),
            'processingOptions'  => $this->processingOptions(),
        ];

        $response = $this->asCpScreen()
            ->title($title)
            ->addCrumb(Craft::t('influx', 'Influx'), 'influx')
            ->addCrumb(Craft::t('influx', 'Links'), 'influx/links')
            ->contentTemplate('influx/links/_edit', $variables);

        if (!$this->readOnly) {
            $response
                ->action('influx/links/save')
                ->redirectUrl('influx/links')
                ->addAltAction(Craft::t('app', 'Save and continue editing'), [
                    'redirect'    => 'influx/links/{handle}/edit',
                    'shortcut'    => true,
                    'retainScroll' => true,
                ]);

            if (!$isNew && $link->uid) {
                $response->destructiveAction(
                    Craft::t('app', 'Delete'),
                    'influx/links/delete',
                    confirmationMessage: Craft::t('influx', 'Are you sure you want to delete this link?'),
                    redirectUrl: 'influx/links',
                );
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

        $link->handle      = (string)$request->getBodyParam('handle', $link->handle);
        $link->name        = (string)$request->getBodyParam('name', $link->name);
        $link->elementType = (string)$request->getBodyParam('elementType', $link->elementType);
        $link->endpoint    = $this->emptyToNull($request->getBodyParam('endpoint'));
        $link->itemEndpoint = $this->emptyToNull($request->getBodyParam('itemEndpoint'));
        $link->rootNode     = $this->emptyToNull($request->getBodyParam('rootNode'));
        $link->paginatorNode = $this->emptyToNull($request->getBodyParam('paginatorNode'));
        $link->backup       = (bool)$request->getBodyParam('backup', false);

        $link->elementCriteria = array_filter(
            $request->getBodyParam('elementCriteria') ?: [],
            fn($v) => $v !== '' && $v !== null,
        );

        $link->headers       = $this->keyValueTable($request->getBodyParam('headers') ?: []);
        $link->siteEndpoints = $this->keyValueTable($request->getBodyParam('siteEndpoints') ?: []);

        $link->match = array_filter([
            'attribute' => $request->getBodyParam('match.attribute') ?: null,
            'source'    => $request->getBodyParam('match.source') ?: null,
        ]);

        $link->mappings = $this->mappingsFromTable($request->getBodyParam('mappings') ?: []);
        $link->ago      = $this->agoFromTable($request->getBodyParam('ago') ?: []);

        $link->processing = array_values(array_filter($request->getBodyParam('processing') ?: []));

        $itemCooldown = $request->getBodyParam('itemCooldown');
        $link->itemCooldown = ($itemCooldown === '' || $itemCooldown === null) ? null : (int)$itemCooldown;
        $batchSize = $request->getBodyParam('batchSize');
        $link->batchSize = ($batchSize === '' || $batchSize === null) ? null : (int)$batchSize;

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

    // -- helpers --------------------------------------------------------

    private function emptyToNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string)$value;
    }

    private function keyValueTable(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $k = trim((string)($row['key'] ?? ''));
            $v = (string)($row['value'] ?? '');
            if ($k === '' || $v === '') {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function mappingsFromTable(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $field = trim((string)($row['field'] ?? ''));
            $type  = trim((string)($row['type'] ?? ''));
            $node  = trim((string)($row['node'] ?? ''));

            if ($field === '' || $type === '') {
                continue;
            }

            $entry = ['type' => $type];
            if ($node !== '') {
                $entry['node'] = $node;
            }

            $options = trim((string)($row['options'] ?? ''));
            if ($options !== '') {
                $decoded = json_decode($options, true);
                if (is_array($decoded)) {
                    $entry['options'] = $decoded;
                }
            }

            $out[$field] = $entry;
        }
        return $out;
    }

    private function agoFromTable(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = trim((string)($row['key'] ?? ''));
            $since = trim((string)($row['since'] ?? ''));
            $queryParam = trim((string)($row['queryParam'] ?? ''));

            if ($key === '' || $since === '' || $queryParam === '') {
                continue;
            }

            $entry = ['since' => $since, 'queryParam' => $queryParam];
            $format = trim((string)($row['format'] ?? ''));
            if ($format !== '') {
                $entry['format'] = $format;
            }

            $out[$key] = $entry;
        }
        return $out;
    }

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

    private function mappingTypeOptions(): array
    {
        $options = [];
        foreach (array_keys(Influx::getInstance()->mapping->all()) as $type) {
            $options[$type] = $type;
        }
        return $options;
    }

    private function siteOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[$site->handle] = $site->name;
        }
        return $options;
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
