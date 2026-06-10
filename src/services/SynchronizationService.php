<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\events\RegisterEndpointTokensEvent;
use TDM\Influx\events\RegisterEndpointTokenSuggestionsEvent;
use TDM\Influx\events\SyncLinkEvent;
use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\exceptions\FeedFetchException;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\OffsetPreset;
use TDM\Influx\models\Link;
use TDM\Influx\records\Log as LogRecord;
use TDM\Influx\targets\ElementTargetInterface;

/**
 * Owns the full sync lifecycle for a link:
 *
 *   1. Pre-run hooks (event, optional backup).
 *   2. For each configured site (or the primary site if none configured),
 *      fetch the JSON payload, walk paginated pages, and process every item.
 *   3. Per item: match-or-build the target element, apply mappings, check for
 *      changes, save (or skip), and log.
 *   4. Post-run hooks (event, log finalisation).
 *
 * Deletion of items that disappeared remotely is intentionally NOT handled in
 * this first cut — see README "Roadmap".
 */
class SynchronizationService extends Component
{
    public const EVENT_BEFORE_SYNC_LINK = 'beforeSyncLink';
    public const EVENT_AFTER_SYNC_LINK = 'afterSyncLink';
    public const EVENT_BEFORE_ITEM = 'beforeItem';
    public const EVENT_AFTER_ITEM_MAPPING = 'afterItemMapping';
    public const EVENT_AFTER_ITEM = 'afterItem';
    public const EVENT_REGISTER_ENDPOINT_TOKENS = 'registerEndpointTokens';
    public const EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS = 'registerEndpointTokenSuggestions';

    /**
     * Custom field classes whose value is a single printable scalar and
     * therefore safe to expose as a Resource Endpoint URL token. Shared by
     * {@see self::tokensForElement()} (runtime value) and
     * {@see self::endpointTokenSuggestions()} (edit-screen picker).
     */
    private const TOKEN_FIELD_TYPES = [
        \craft\fields\Dropdown::class,
        \craft\fields\Email::class,
        \craft\fields\Number::class,
        \craft\fields\PlainText::class,
        \craft\fields\RadioButtons::class,
    ];

    /**
     * Hard ceiling on pages followed per site, guarding against endless
     * paginator chains that never repeat a URL. Cycles are caught separately
     * via a seen-URL set in {@see self::processSite()}.
     */
    private const MAX_PAGES = 500;

    /**
     * Run a full link sync.
     *
     * @param string|null $offset Key into $link->offset presets, applied as a query param.
     * @param string $trigger One of: console, cp, queue, element
     */
    public function syncLink(
        Link $link,
        ?string $offset = null,
        string $trigger = 'console',
    ): LogRecord {
        $plugin = Influx::getInstance();

        $beforeEvent = new SyncLinkEvent(['link' => $link]);
        $this->trigger(self::EVENT_BEFORE_SYNC_LINK, $beforeEvent);
        if (!$beforeEvent->isValid) {
            throw new InfluxException("Link '{$link->handle}' run cancelled by a beforeSyncLink listener.");
        }

        $plugin->backup->backupForLink($link);

        $log = $plugin->logs->start($link, $trigger);

        $target = $this->resolveTarget($link);

        [$queryParams] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $this->processSite($link, $target, $siteHandle, $queryParams, $log);
            }

            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        $afterEvent = new SyncLinkEvent([
            'link'           => $link,
            'itemsSeen'      => (int)$log->itemsSeen,
            'itemsCreated'   => (int)$log->itemsCreated,
            'itemsUpdated'   => (int)$log->itemsUpdated,
            'itemsUnchanged' => (int)$log->itemsUnchanged,
            'itemsSkipped'   => (int)$log->itemsSkipped,
            'itemsDeleted'   => (int)$log->itemsDeleted,
        ]);
        $this->trigger(self::EVENT_AFTER_SYNC_LINK, $afterEvent);

        return $log;
    }

    /**
     * Sync a single existing element from its link's itemEndpoint (the
     * per-entry "Sync from remote" button).
     */
    public function syncElement(Link $link, ElementInterface $element): LogRecord
    {
        $plugin = Influx::getInstance();
        $target = $this->resolveTarget($link);
        $matchAttr = $link->matchAttribute()
            ?? throw new InfluxException("Link '{$link->handle}' has no match attribute.");

        $matchValue = $element->$matchAttr;
        if (!$matchValue) {
            throw new InfluxException("Element #{$element->id} has no value on '{$matchAttr}'.");
        }

        $log = $plugin->logs->start($link, 'element');

        try {
            $siteHandles = $link->siteHandles() ?: [null];

            foreach ($siteHandles as $siteHandle) {
                $tokens = $this->tokensForElement($link, $element, $siteHandle);
                $item = $plugin->data->fetchOne($link, $tokens);
                $this->processItem($link, $target, $item, $siteHandle, $log);
            }

            $plugin->cooldown->mark($link, $element);
            $plugin->logs->finish($log);
        } catch (\Throwable $e) {
            $plugin->logs->fail($log, $e->getMessage());
            throw $e;
        }

        return $log;
    }

    /**
     * Build the token map used by the link's Resource Endpoint URL template.
     *
     * Exposes a small, predictable set:
     *   - Native attributes: {id}, {status}, {slug}
     *   - Current site: {site.id}, {site.handle}, {site.locale}
     *   - Custom fields, limited to the field types whose value is a single
     *     printable scalar: Dropdown, Email, Number, PlainText, RadioButtons.
     *
     * Anything else (relations, assets, matrices, dates, lightswitches, ...)
     * is intentionally not exposed — they don't have an obvious URL form.
     * Plugins can contribute more tokens via {@see self::EVENT_REGISTER_ENDPOINT_TOKENS}.
     *
     * @return array<string, string>
     */
    private function tokensForElement(Link $link, ElementInterface $element, ?string $siteHandle): array
    {
        $tokens = [];

        foreach (['id', 'status', 'slug'] as $attr) {
            $v = $element->$attr ?? null;
            if (is_scalar($v) && $v !== '') {
                $tokens[$attr] = (string)$v;
            }
        }

        $site = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : (method_exists($element, 'getSite') ? $element->getSite() : null);
        if ($site) {
            $tokens['site.id']     = (string)$site->id;
            $tokens['site.handle'] = $site->handle;
            $tokens['site.locale'] = $site->language;
        }

        if (method_exists($element, 'getFieldLayout')) {
            $layout = $element->getFieldLayout();
            if ($layout) {
                foreach ($layout->getCustomFields() as $field) {
                    if (!in_array($field::class, self::TOKEN_FIELD_TYPES, true)) {
                        continue;
                    }
                    $handle = $field->handle;
                    if (isset($tokens[$handle])) {
                        continue;
                    }
                    $v = $element->getFieldValue($handle);
                    if ($v !== null && (string)$v !== '') {
                        $tokens[$handle] = (string)$v;
                    }
                }
            }
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_ENDPOINT_TOKENS)) {
            $event = new RegisterEndpointTokensEvent([
                'link'       => $link,
                'element'    => $element,
                'siteHandle' => $siteHandle,
                'tokens'     => $tokens,
            ]);
            $this->trigger(self::EVENT_REGISTER_ENDPOINT_TOKENS, $event);
            $tokens = $event->tokens;
        }

        return $tokens;
    }

    /**
     * Token suggestions surfaced by the link edit screen's "Insert token"
     * picker on the Resource Endpoint input. Mirrors {@see self::tokensForElement()}
     * so what the picker advertises matches what's actually substituted at
     * sync-time. Plugins can append more via
     * {@see self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS}.
     *
     * @return list<array{label: string, data: list<array{name: string, hint?: string}>}>
     */
    public function endpointTokenSuggestions(Link $link): array
    {
        $suggestions = [
            [
                'kind'  => 'element',
                'label' => Craft::t('influx', 'Element'),
                'data'  => [
                    ['name' => '{id}',     'hint' => Craft::t('influx', 'Element ID')],
                    ['name' => '{status}', 'hint' => Craft::t('influx', 'Status')],
                    ['name' => '{slug}',   'hint' => Craft::t('influx', 'Slug')],
                ],
            ],
            [
                'kind'  => 'site',
                'label' => Craft::t('influx', 'Site'),
                'data'  => [
                    ['name' => '{site.id}',     'hint' => Craft::t('influx', 'Site ID')],
                    ['name' => '{site.handle}', 'hint' => Craft::t('influx', 'Site handle')],
                    ['name' => '{site.locale}', 'hint' => Craft::t('influx', 'Site locale')],
                ],
            ],
        ];

        $fieldItems = [];
        foreach ($this->customFieldsForLink($link) as $field) {
            if (!in_array($field::class, self::TOKEN_FIELD_TYPES, true)) {
                continue;
            }
            $fieldItems[] = [
                'name' => '{' . $field->handle . '}',
                'hint' => $field->name,
            ];
        }
        if ($fieldItems) {
            $suggestions[] = [
                'kind'  => 'fields',
                'label' => Craft::t('influx', 'Fields'),
                'data'  => $fieldItems,
            ];
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS)) {
            $event = new RegisterEndpointTokenSuggestionsEvent([
                'link'        => $link,
                'suggestions' => $suggestions,
            ]);
            $this->trigger(self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS, $event);
            $suggestions = $event->suggestions;
        }

        return $suggestions;
    }

    /**
     * Custom fields on the entry type that the configured link points at,
     * or an empty list when the link has no section/type yet. Used by the
     * token picker; runtime token-building reads the live element's layout.
     *
     * @return list<\craft\base\FieldInterface>
     */
    private function customFieldsForLink(Link $link): array
    {
        $sectionHandle = $link->elementCriteria['section'] ?? null;
        $typeHandle    = $link->elementCriteria['type'] ?? null;
        if (!$sectionHandle) {
            return [];
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return [];
        }

        $entryTypes = $section->getEntryTypes();
        $entryType = null;
        if ($typeHandle) {
            foreach ($entryTypes as $candidate) {
                if ($candidate->handle === $typeHandle) {
                    $entryType = $candidate;
                    break;
                }
            }
        }
        $entryType ??= $entryTypes[0] ?? null;
        if (!$entryType) {
            return [];
        }

        $layout = $entryType->getFieldLayout();
        return $layout ? $layout->getCustomFields() : [];
    }

    /**
     * Fetch the (paginated) feed for one site and process every item.
     *
     * @throws FeedFetchException on fetch failures, paginator URL cycles, or
     * pagination running past {@see self::MAX_PAGES}.
     */
    private function processSite(
        Link $link,
        ElementTargetInterface $target,
        ?string $siteHandle,
        array $queryParams,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $data = $plugin->data->fetch($link, $siteHandle, $queryParams);

        $seenUrls = [];
        $pages = 1;

        while (true) {
            foreach ($plugin->data->rootList($link, $data) as $item) {
                $this->processItem($link, $target, $item, $siteHandle, $log);
            }

            $nextUrl = $link->paginatorNode
                ? \Cake\Utility\Hash::get($data, $link->paginatorNode)
                : null;

            if (!$nextUrl) {
                break;
            }

            $urlKey = (string)$nextUrl;
            if (isset($seenUrls[$urlKey])) {
                throw new FeedFetchException("Pagination loop detected: '{$urlKey}' was already fetched (paginator node '{$link->paginatorNode}').");
            }
            if (++$pages > self::MAX_PAGES) {
                throw new FeedFetchException('Pagination exceeded ' . self::MAX_PAGES . " pages — aborting (paginator node '{$link->paginatorNode}').");
            }
            $seenUrls[$urlKey] = true;

            $nextHeaders = [];
            $nextQuery = [];
            $link->applyAuth($nextHeaders, $nextQuery);
            $data = $plugin->data->fetchUrl($nextUrl, $nextHeaders, $nextQuery);
        }
    }

    private function processItem(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
        LogRecord $log,
    ): void {
        $plugin = Influx::getInstance();
        $matchValue = $link->matchValue($item);

        if ($matchValue === null) {
            $matchAttr = $link->matchAttribute() ?: '?';
            $node = $link->mappings[$matchAttr]['node'] ?? '?';
            $plugin->logs->recordItem(
                $log,
                'skipped',
                null,
                null,
                "Remote item has no value at match path '{$node}' (match attribute: {$matchAttr}).",
                $item,
            );
            return;
        }

        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        $element = $target->findByMatchValue($link, $matchValue, $siteId);

        $beforeEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_BEFORE_ITEM, $beforeEvent);

        if ($beforeEvent->skip) {
            $plugin->logs->recordItem($log, 'skipped', $element?->id, (string)$matchValue, null, $item);
            return;
        }

        // Allow listeners to swap in a different element.
        $element = $beforeEvent->element;

        switch ($link->decideAction($matchValue, $element)) {
            case Link::DECISION_SKIP_NO_CREATE:
                $plugin->logs->recordItem($log, 'skipped', null, (string)$matchValue, "No existing element and 'create' not enabled.", $item);
                return;
            case Link::DECISION_SKIP_NO_UPDATE:
                $plugin->logs->recordItem($log, 'skipped', $element->id, (string)$matchValue, "'update' not enabled for this link.", $item);
                return;
            case Link::DECISION_CREATE:
                $element = $target->buildNew($link, $siteId);
                $target->assignMatchValue($element, $link, $matchValue);
                $isNew = true;
                break;
            case Link::DECISION_UPDATE:
            default:
                $isNew = false;
                break;
        }

        if ($siteId) {
            $element->siteId = $siteId;
        }

        $changed = (new \TDM\Influx\sync\MappingApplier())->apply($target, $element, $link, $item, $isNew);

        $afterMappingEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM_MAPPING, $afterMappingEvent);

        if (!$changed) {
            $plugin->logs->recordItem($log, 'unchanged', $element->id, (string)$matchValue, null, $item);
            $this->fireAfterItem($link, $item, $element, $siteHandle, 'unchanged');
            return;
        }

        $saved = Craft::$app->getElements()->saveElement($element, false);

        $action = !$saved ? 'error' : ($isNew ? 'created' : 'updated');
        $plugin->logs->recordItem(
            $log,
            $action,
            $element->id,
            (string)$matchValue,
            $saved ? null : json_encode($element->getErrors()),
            $item,
        );

        $this->fireAfterItem($link, $item, $element, $siteHandle, $action);
    }

    private function fireAfterItem(
        Link $link,
        array $item,
        ElementInterface $element,
        ?string $siteHandle,
        string $action,
    ): void {
        $afterEvent = new SyncItemEvent([
            'link'       => $link,
            'item'       => $item,
            'element'    => $element,
            'siteHandle' => $siteHandle,
            'action'     => $action,
        ]);
        $this->trigger(self::EVENT_AFTER_ITEM, $afterEvent);
    }

    private function resolveTarget(Link $link): ElementTargetInterface
    {
        $target = Influx::getInstance()->targets->forLink($link);
        if (!$target) {
            throw new InfluxException("No element target registered for '{$link->elementType}'.");
        }
        return $target;
    }

}
