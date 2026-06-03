<?php

namespace TDM\Influx\models;

use Cake\Utility\Hash;
use craft\base\Model;
use craft\helpers\App;

/**
 * Represents a single feed defined by a YAML file under config/influx.
 *
 * Hydrated from an array; nothing here writes back to disk. Duplication is a
 * separate operation that writes a new YAML file (see FeedsService::duplicate).
 */
class Feed extends Model
{
    public string $handle = '';

    public string $name = '';

    /**
     * FQCN of the target element type (e.g. craft\elements\Entry).
     */
    public string $elementType = '';

    /**
     * Criteria used to scope element-type queries (e.g. ['section' => 'news',
     * 'type' => 'article']). Forwarded to the configured ElementTarget so it
     * can both build the find-query and set required attributes on new
     * elements.
     */
    public array $elementCriteria = [];

    /**
     * Default endpoint when no per-site endpoint is configured.
     */
    public ?string $endpoint = null;

    /**
     * Endpoint pattern for syncing a single remote resource. Tokens like
     * `{id}` are substituted at sync-time. Used by the per-element "sync now"
     * button.
     */
    public ?string $itemEndpoint = null;

    /**
     * Map of siteHandle => endpoint. When set, the feed runs once per site,
     * fetching the localized payload and writing to that site's row on the
     * matched element. When empty, the primary site's endpoint is used.
     */
    public array $siteEndpoints = [];

    /**
     * HTTP headers, with values resolved through App::parseEnv so secrets can
     * live in .env (e.g. ['Authorization' => '$REMOTE_FEED_TOKEN']).
     */
    public array $headers = [];

    /**
     * Where the iterable list of items lives within the JSON response (Hash
     * dot-path; null means the response itself is the list).
     */
    public ?string $rootNode = null;

    /**
     * Hash dot-path that yields the next-page URL (cursor-style pagination).
     */
    public ?string $paginatorNode = null;

    /**
     * { attribute: importId, source: id }
     *  - attribute: handle on the element used as the unique key
     *  - source: Hash dot-path into the item used to look it up
     */
    public array $match = [];

    /**
     * Map of element field-handle => mapping config:
     *   title:
     *     type: PlainText
     *     node: title.rendered
     *
     * The mapping classes themselves are registered with MappingService.
     */
    public array $mappings = [];

    /**
     * Allowed processing actions for items that have left the remote feed
     * since the last sync. Subset of: create, update, disable, delete,
     * delete-for-site.
     */
    public array $processing = ['create', 'update'];

    /**
     * Sliding-window sync presets, e.g.
     *   ago:
     *     hour:   { since: '-1 hour',   queryParam: modified_since }
     *     day:    { since: '-1 day',    queryParam: modified_since }
     *     week:   { since: '-1 week',   queryParam: modified_since }
     *
     * Available to console (`--ago=hour`) and CP.
     */
    public array $ago = [];

    /**
     * Cooldown (seconds) between manual per-element syncs. Null falls back to
     * Settings::$defaultItemCooldown.
     */
    public ?int $itemCooldown = null;

    /**
     * Batch size for paginated processing. Null falls back to Settings.
     */
    public ?int $batchSize = null;

    /**
     * Take a DB backup before this feed runs.
     */
    public bool $backup = false;

    /**
     * Absolute filesystem path of the YAML file this feed was loaded from.
     * Set by FeedsService.
     */
    public ?string $sourceFile = null;

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['handle', 'name', 'elementType'], 'required'],
            [['endpoint', 'itemEndpoint'], 'string'],
        ]);
    }

    /**
     * Resolved headers with env values parsed.
     */
    public function resolvedHeaders(): array
    {
        $resolved = [];
        foreach ($this->headers as $name => $value) {
            $resolved[$name] = App::parseEnv($value);
        }
        return $resolved;
    }

    /**
     * Endpoint to use for the given site handle, falling back to the default
     * endpoint when no per-site endpoint is configured.
     */
    public function endpointForSite(string $siteHandle): ?string
    {
        $endpoint = $this->siteEndpoints[$siteHandle] ?? $this->endpoint;
        return $endpoint ? App::parseEnv($endpoint) : null;
    }

    /**
     * Sites this feed targets. When siteEndpoints is empty we target the
     * primary site only.
     */
    public function siteHandles(): array
    {
        return !empty($this->siteEndpoints) ? array_keys($this->siteEndpoints) : [];
    }

    /**
     * Pull the match value out of one remote item.
     */
    public function matchValue(array $item): mixed
    {
        $path = $this->match['source'] ?? null;
        return $path ? Hash::get($item, $path) : null;
    }

    public function matchAttribute(): ?string
    {
        return $this->match['attribute'] ?? null;
    }

    public function effectiveItemCooldown(int $default): int
    {
        return $this->itemCooldown ?? $default;
    }

    public function effectiveBatchSize(int $default): int
    {
        return $this->batchSize ?? $default;
    }
}
