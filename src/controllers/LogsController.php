<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\Permission;
use GlueAgency\Influx\enums\RunStatus;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\services\LogsService;
use GlueAgency\Influx\web\LinkPresenter;
use GlueAgency\Influx\web\LogPresenter;
use GlueAgency\Influx\web\LogViewerTranslations;
use GlueAgency\Influx\web\Vocabulary;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class LogsController extends AbstractController
{
    /**
     * Access to the section is one permission; which runs are visible inside it
     * is another, granted per link and checked against the run itself
     * ({@see requireViewLog()}). Removing rows takes the delete permission on
     * top — and never reaches further than the runs that user can see, so a
     * per-link viewer's "Clear all" clears their links' runs, not the log.
     */
    protected function requireAccess(Action $action): void
    {
        parent::requireAccess($action);

        $this->requirePermission(Permission::ACCESS_LOGS->value);

        if (in_array($action->id, ['delete', 'clear'], true)) {
            $this->requirePermission(Permission::DELETE_LOGS->value);
        }
    }

    /**
     * The per-run gate: a run is visible to whoever may see its link's runs.
     * A run whose link has since been deleted has no link left to have been
     * granted, so only the blanket permission reaches it.
     *
     * @throws ForbiddenHttpException
     */
    protected function requireViewLog(LogRecord $log): void
    {
        $plugin = Influx::getInstance();
        $link = $plugin->links->getLinkByHandle($log->linkHandle);

        if ($link !== null && $plugin->permissions->canViewLogsForLink($link)) {
            return;
        }

        if ($plugin->permissions->can(Permission::VIEW_LOGS)) {
            return;
        }

        throw new ForbiddenHttpException(Craft::t('influx', 'You don’t have permission to view this run.'));
    }

    /**
     * Every toolbar filter is validated against what currently exists (or, for
     * status, trigger and result, against its enum) by {@see oneOfQueryParam()},
     * so a stale query string falls back to "all". The `handle => name` map
     * behind the filter and the `handle => chip` map the rows render carry no
     * entry for a link that has since been deleted — the row then degrades to the
     * handle the run stored.
     *
     * The page's triggering users are chipped from one query up front
     * ({@see LogPresenter::userChips()}) rather than per row — 50 rows, 50
     * lookups otherwise.
     */
    public function actionIndex(): Response
    {
        $page = $this->intQueryParam('page', 1, 1);

        $plugin = Influx::getInstance();

        // Which links' runs this user may see — null for no restriction. It
        // bounds the list, the empty state's count, and the link filter's
        // options alike, so the toolbar can't name a link the list can't show.
        $allowedLinkHandles = $plugin->permissions->viewableLogLinkHandles();

        $links = $plugin->links->getAllLinks();

        if ($allowedLinkHandles !== null) {
            $links = array_filter($links, static fn($link) => in_array($link->handle, $allowedLinkHandles, true));
        }

        $linkNames = array_map(static fn($link) => $link->name, $links);
        $linkChips = array_map(static fn($link) => Template::raw(Compat::linkChipHtml($link)), $links);

        $statuses = [];

        foreach (RunStatus::cases() as $status) {
            $statuses[$status->value] = $status->label();
        }

        $triggers = [];

        foreach (SyncTrigger::cases() as $trigger) {
            $triggers[$trigger->value] = $trigger->label();
        }

        // The result kinds a run can be filtered to are its counters, in the
        // order the result pills render — the values only, their nouns being the
        // overview's to translate.
        $results = array_map(static fn(ItemAction $action) => $action->value, ItemAction::countedCases());

        $selectedLink = $this->oneOfQueryParam('link', array_keys($linkNames));
        $selectedStatus = $this->oneOfQueryParam('status', array_keys($statuses));
        $selectedTrigger = $this->oneOfQueryParam('trigger', array_keys($triggers));
        $selectedResult = $this->oneOfQueryParam('result', $results);

        ['logs' => $logs, 'total' => $total] = $plugin->logs->paginate(
            $page,
            LogsService::LOGS_PER_PAGE,
            $selectedLink,
            $selectedStatus,
            $selectedTrigger,
            $selectedResult,
            $allowedLinkHandles,
        );

        $presenter = new LogPresenter();

        // Only the empty state asks this — it tells an operator whose filters
        // matched nothing how many runs they'd see without them. Null (rather
        // than a count nobody reads) on any page that has rows.
        $unfilteredTotal = $logs === [] ? $plugin->logs->totalCount($allowedLinkHandles) : null;

        return $this->renderTemplate('influx/logs/index', [
            'logs'            => $logs,
            'page'            => $page,
            'perPage'         => LogsService::LOGS_PER_PAGE,
            'total'           => $total,
            'unfilteredTotal' => $unfilteredTotal,
            'linkNames'       => $linkNames,
            'linkChips'       => $linkChips,
            'presenter'       => $presenter,
            'userChips'       => $presenter->userChips($logs),
            'selectedLink'    => $selectedLink,
            'selectedStatus'  => $selectedStatus,
            'statuses'        => $statuses,
            'selectedTrigger' => $selectedTrigger,
            'triggers'        => $triggers,
            'selectedResult'  => $selectedResult,
            'results'         => $results,
            'retentionDays'   => $plugin->getSettings()->logRetentionDays,
        ]);
    }

    /**
     * Only the first page of items ships in the bootstrap; the rest pages in via
     * {@see actionItems()}. The "Endpoint" and "Resource" rows are Link-derived
     * display, so {@see LinkPresenter} builds them.
     */
    public function actionView(int $id): Response
    {
        $log = $this->logOr404($id);

        $this->requireViewLog($log);

        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();
        $links = new LinkPresenter();

        $link = $plugin->links->getLinkByHandle($log->linkHandle);
        $elementId = $log->elementId !== null ? (int) $log->elementId : null;

        $items = $presenter->presentItems(
            $plugin->logs->itemPage($log, [], 0, LogsService::ITEMS_PER_PAGE),
            $link?->elementType,
        );

        ['endpointUrl' => $endpointUrl, 'endpoints' => $endpoints] = $links->endpointDisplay($link, $elementId, $log->siteHandle);

        // Each per-site endpoint line is labelled with its site's chip. Rendered
        // here rather than in the presenter, whose endpoint helpers stay pure
        // (primitives in, primitives out) so they need no booted Craft to test.
        if ($endpoints !== null) {
            $endpoints = array_map(
                static fn(array $row): array => ['chipHtml' => Compat::siteChipHtml($row['site'])] + $row,
                $endpoints,
            );
        }

        $this->registerAppTranslations(LogViewerTranslations::strings());

        return $this->renderTemplate('influx/logs/view', [
            'config' => [
                'log'             => $presenter->presentLog($log),
                'items'           => $items,
                'itemTotal'       => $plugin->logs->itemCount($log, []),
                'perPage'         => LogsService::ITEMS_PER_PAGE,
                'itemsUrl'        => UrlHelper::cpUrl("influx/logs/{$log->id}/items"),
                'itemUrlTemplate' => UrlHelper::cpUrl('influx/logs/items/__ID__'),
                'linkId'          => $link?->id,
                'linkName'        => $link?->name,
                'endpointUrl'     => $endpointUrl,
                'endpoints'       => $endpoints,
                'siteChipHtml'    => Compat::siteChipHtml($log->siteHandle),
                'resourceHtml'    => $links->resourceDisplay($link, $elementId),
                'isLive'          => LogPresenter::isLive($log->status),
                'pollInterval'    => $plugin->getSettings()->logPollInterval,
                'vocabulary'      => Vocabulary::payload(),
            ],
        ]);
    }

    /**
     * Drill-down for one stored log item — the row is presented from what the
     * run stored, degradations and the "link is gone" answer included, all in
     * {@see \GlueAgency\Influx\services\InspectorService::inspectStoredLogItem()}.
     */
    public function actionItem(int $id): Response
    {
        $this->requireAcceptsJson();

        $item = $this->logItemOr404($id);
        $log = $this->logOr404((int) $item->logId);

        $this->requireViewLog($log);

        return $this->asJson(Influx::getInstance()->inspector->inspectStoredLogItem($item, $log));
    }

    /**
     * JSON endpoint backing the log-detail item list. Returns one page of
     * items (newest first, optionally filtered to a set of `actions`), the
     * total matching that filter, the refreshed counters/status, and whether
     * the run has settled. Used for both pager navigation and the live poll
     * (the client re-requests the page in view on an interval while running —
     * Craft's queue-runner pattern — rather than holding an SSE connection
     * and the PHP session lock open for the whole run).
     *
     * The single-select action filter arrives as `status`, not `action` — Craft
     * reserves `action` for routing. A known action expands to its filter group,
     * so a per-site variant is served alongside its base and the list matches
     * the grouped counter.
     */
    public function actionItems(int $id): Response
    {
        $this->requireAcceptsJson();

        $log = $this->logOr404($id);

        $this->requireViewLog($log);

        $page = $this->intQueryParam('page', 1, 1);
        $action = $this->stringQueryParam('status');
        $case = $action !== null ? ItemAction::tryFrom($action) : null;
        $actions = $case !== null ? $case->filterGroup() : ($action !== null ? [$action] : []);
        $search = $this->stringQueryParam('search');

        $plugin = Influx::getInstance();
        $presenter = new LogPresenter();
        $offset = ($page - 1) * LogsService::ITEMS_PER_PAGE;

        $link = $plugin->links->getLinkByHandle($log->linkHandle);
        $items = $presenter->presentItems(
            $plugin->logs->itemPage($log, $actions, $offset, LogsService::ITEMS_PER_PAGE, $search),
            $link?->elementType,
        );

        return $this->asJson([
            'items'    => $items,
            'total'    => $plugin->logs->itemCount($log, $actions, $search),
            'counters' => $presenter->presentCounters($log),
            'done'     => ! LogPresenter::isLive($log->status),
        ]);
    }

    /**
     * POST influx/logs/delete — drops one log row (its items cascade). Only a
     * run this user can see, so the delete permission never reaches past the
     * list it was granted alongside.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');
        $log = $this->logOr404($id);

        $this->requireViewLog($log);

        Influx::getInstance()->logs->delete($log);

        return $this->asSuccess(Craft::t('influx', 'Log #{id} deleted.', ['id' => $id]));
    }

    /**
     * POST influx/logs/clear — drops the log rows this user can see: every one
     * of them under the blanket view permission, their own links' runs under a
     * per-link grant. "Clear all" clears the list it sits under, whichever that
     * is.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $plugin = Influx::getInstance();
        $deleted = $plugin->logs->clear($plugin->permissions->viewableLogLinkHandles());

        return $this->asSuccess(
            Craft::t('influx', '{n, plural, =1{One log entry} other{# log entries}} cleared.', ['n' => $deleted]),
        );
    }
}
