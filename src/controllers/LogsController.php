<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\RunStatus;
use GlueAgency\Influx\enums\SyncTrigger;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\services\LogsService;
use GlueAgency\Influx\web\LinkPresenter;
use GlueAgency\Influx\web\LogPresenter;
use GlueAgency\Influx\web\LogViewerTranslations;
use GlueAgency\Influx\web\Vocabulary;
use yii\web\Response;

class LogsController extends AbstractController
{
    /**
     * Every toolbar filter is validated against what currently exists (or, for
     * status, trigger and result, against its enum) by {@see oneOfQueryParam()},
     * so a stale query string falls back to "all". The `handle => name` map behind the
     * filter and the `handle => chip` map the rows render carry no entry for a
     * link that has since been deleted — the row then degrades to the handle the
     * run stored.
     *
     * The page's triggering users are chipped from one query up front
     * ({@see LogPresenter::userChips()}) rather than per row — 50 rows, 50
     * lookups otherwise.
     */
    public function actionIndex(): Response
    {
        $page = $this->intQueryParam('page', 1, 1);

        $plugin = Influx::getInstance();

        $links = $plugin->links->getAllLinks();
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
        );

        $presenter = new LogPresenter();

        return $this->renderTemplate('influx/logs/index', [
            'logs'            => $logs,
            'page'            => $page,
            'perPage'         => LogsService::LOGS_PER_PAGE,
            'total'           => $total,
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
     * POST influx/logs/delete — drops one log row (its items cascade).
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = (int) Craft::$app->getRequest()->getRequiredBodyParam('id');

        Influx::getInstance()->logs->delete($this->logOr404($id));

        return $this->asSuccess(Craft::t('influx', 'Log #{id} deleted.', ['id' => $id]));
    }

    /**
     * POST influx/logs/clear — drops every log row.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Influx::getInstance()->logs->clear();

        return $this->asSuccess(Craft::t('influx', '{n} log entries cleared.', ['n' => $deleted]));
    }
}
