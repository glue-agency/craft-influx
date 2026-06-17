<?php

namespace GlueAgency\Influx\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * An Influx link: one configured connection between Craft and an external
 * JSON API.
 *
 * Links are stored in Project Config under `influx.links.{uid}` — they
 * round-trip to YAML the same way sections, entry types, volumes, etc. do,
 * and obey the same `allowAdminChanges` gating.
 */
class Link extends Model
{
    public const PROCESSING_CREATE = 'create';
    public const PROCESSING_UPDATE = 'update';
    public const PROCESSING_DISABLE = 'disable';
    public const PROCESSING_DELETE = 'delete';
    public const PROCESSING_DELETE_FOR_SITE = 'delete-for-site';

    public const ALL_PROCESSING = [
        self::PROCESSING_CREATE,
        self::PROCESSING_UPDATE,
        self::PROCESSING_DISABLE,
        self::PROCESSING_DELETE,
        self::PROCESSING_DELETE_FOR_SITE,
    ];

    /** @deprecated Use {@see SyncDecision::CREATE} — {@see self::decideAction()} returns the enum now. */
    public const DECISION_CREATE = self::PROCESSING_CREATE;
    /** @deprecated Use {@see SyncDecision::UPDATE}. */
    public const DECISION_UPDATE = self::PROCESSING_UPDATE;
    /** @deprecated Use {@see SyncDecision::SKIP_NO_MATCH}. */
    public const DECISION_SKIP_NO_MATCH = 'skip:no-match';
    /** @deprecated Use {@see SyncDecision::SKIP_NO_CREATE}. */
    public const DECISION_SKIP_NO_CREATE = 'skip:no-create';
    /** @deprecated Use {@see SyncDecision::SKIP_NO_UPDATE}. */
    public const DECISION_SKIP_NO_UPDATE = 'skip:no-update';

    /**
     * The config fields a Link serialises to — its Project Config keys, which
     * are also its `influx_links` columns. THE single source of truth for
     * "what fields does a Link have": {@see getConfig()} builds from this and
     * {@see \GlueAgency\Influx\services\LinksService} maps the same fields onto
     * DB columns, rather than each re-listing them.
     *
     * Empty-shape contract: {@see getConfig()} strips empty values, so an empty
     * field is absent from Project Config and stored as NULL; the service reads
     * a missing JSON column back as `[]`; {@see toBuilderArray()} casts the
     * array-y fields to `{}` for the SPA. Three representations, reconciled here.
     */
    public const CONFIG_FIELDS = [
        'handle',
        'name',
        'elementType',
        'elementCriteria',
        'endpoint',
        'itemEndpoint',
        'siteEndpoints',
        'auth',
        'rootNode',
        'paginatorNode',
        'match',
        'mappings',
        'processing',
        'offset',
        'backup',
    ];

    /**
     * The subset of {@see CONFIG_FIELDS} stored as JSON-encoded columns — used
     * for both encode (DB write) and decode (DB read) so the two stay symmetric.
     */
    public const JSON_FIELDS = [
        'elementCriteria',
        'siteEndpoints',
        'auth',
        'match',
        'mappings',
        'processing',
        'offset',
    ];

    public ?int $id = null;

    public ?string $uid = null;

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
     * Endpoint pattern for syncing a single remote resource. Used by the
     * per-element "Sync from remote" button. Tokens substituted at sync-time
     * are built by {@see \GlueAgency\Influx\services\SynchronizationService::tokensForElement()}:
     * `{id}`, `{status}`, `{slug}`, `{site.id}`, `{site.handle}`,
     * `{site.locale}`, plus any Dropdown/Email/Number/PlainText/RadioButtons
     * custom field referenced by its handle.
     */
    public ?string $itemEndpoint = null;

    /**
     * Per-site endpoints as an ordered list of
     * `['site' => handle, 'endpoint' => url]`. When set, the link runs once
     * per site — in this order — fetching the localized payload and writing
     * to that site's row on the matched element.
     *
     * Stored as a list rather than a `{handle: url}` map because Project
     * Config alphabetizes associative-array keys on save
     * ({@see \craft\helpers\ProjectConfig::cleanupConfig()} ksorts), which
     * would discard the configured run order; ordered lists round-trip
     * intact. Always assigned through {@see setSiteEndpoints()} so legacy map
     * configs still hydrate.
     *
     * @var list<array{site: string, endpoint: string}>
     */
    protected array $siteEndpoints = [];

    /**
     * Authentication configuration. Stored shape:
     *
     *   ['type' => 'basic',       'token' => '$INFLUX_PASSWORD', 'username' => '$INFLUX_USER']
     *   ['type' => 'bearer',      'token' => '$INFLUX_TOKEN']
     *   ['type' => 'custom',      'token' => '$INFLUX_TOKEN', 'header' => 'X-API-Key']
     *   ['type' => 'querystring', 'token' => '$INFLUX_TOKEN', 'param'  => 'api_key']
     *
     * Empty array means no auth.
     */
    public array $auth = [];

    /**
     * Where the iterable list of items lives within the JSON response (Hash
     * dot-path; null means the response itself is the list).
     */
    public ?string $rootNode = null;

    /**
     * Hash dot-path that yields the next-page URL.
     */
    public ?string $paginatorNode = null;

    /**
     * { attribute: importId }
     *  - attribute: handle on the element used as the unique key. The match
     *    value is always read from this field's configured mapping node.
     */
    public array $match = [];

    /**
     * Map of element field-handle => mapping config.
     */
    public array $mappings = [];

    /**
     * Allowed actions. Subset of {@see self::ALL_PROCESSING}.
     */
    public array $processing = [self::PROCESSING_CREATE, self::PROCESSING_UPDATE];

    /**
     * Sliding-window sync presets, e.g.
     *   offset:
     *     hour: { since: '-1 hour', queryParam: modified_since }
     */
    public array $offset = [];

    /**
     * Take a DB backup before this link runs.
     */
    public bool $backup = false;

    /** Memoized typed view over $mappings — see {@see getMappingCollection()}. */
    protected ?MappingCollection $mappingCollection = null;

    /** The raw $mappings array the memo was built from, for staleness checks. */
    protected ?array $mappingCollectionSource = null;

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['handle', 'name', 'elementType'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and dashes.'],
            [['endpoint', 'itemEndpoint'], 'string'],
            [['endpoint'], 'required', 'when' => fn(self $m) => empty($m->siteEndpoints), 'message' => 'Either an endpoint or at least one site endpoint is required.'],
            [['match'], 'validateMatch'],
            [['auth'], 'validateAuth'],
            [['processing'], 'each', 'rule' => ['in', 'range' => self::ALL_PROCESSING]],
        ]);
    }

    public function validateMatch(string $attribute): void
    {
        $value = $this->$attribute;

        if (! is_array($value) || empty($value['attribute'])) {
            $this->addError($attribute, 'Match must declare an `attribute`.');

            return;
        }

        // The match value is read from the node configured on the mapped
        // field, so the chosen match attribute must have an active mapping.
        $mappedNode = $this->getMappingCollection()->get($value['attribute'])?->node;

        if (! $mappedNode) {
            $this->addError(
                $attribute,
                "Match attribute '{$value['attribute']}' needs a configured mapping with a source node.",
            );
        }
    }

    public function validateAuth(string $attribute): void
    {
        $value = $this->$attribute;

        if (empty($value)) {
            return;
        }

        $auth = $this->authService();
        $strategy = $auth?->fromConfig($value);

        if (! $strategy) {
            $known = $auth ? implode(', ', $auth->knownTypes()) : '?';
            $this->addError($attribute, "Auth type must be one of: {$known}.");

            return;
        }

        if (! $strategy->validate()) {
            foreach ($strategy->getFirstErrors() as $msg) {
                $this->addError($attribute, $msg);
            }
        }
    }

    /**
     * Make sure every link has a UID. Called by LinksService::save() before
     * writing to Project Config.
     */
    public function ensureUid(): void
    {
        if (! $this->uid) {
            $this->uid = StringHelper::UUID();
        }
    }

    /**
     * Project Config payload — the same shape as the YAML on disk.
     */
    public function getConfig(): array
    {
        $config = [];

        foreach (self::CONFIG_FIELDS as $field) {
            $config[$field] = $this->{$field};
        }

        return array_filter($config, function($value) {
            if ($value === null || $value === '' || $value === false) {
                return false;
            }

            if (is_array($value) && empty($value)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Marshal this link into the JSON wire shape the LinkBuilder SPA
     * consumes — the single authority for that contract (the JS side
     * documents it in `builder/types.js` and asserts it against the
     * committed fixture in `tests/fixtures/link-payload.json`).
     *
     * Lives next to {@see getConfig()} deliberately: one model, all of its
     * serialized shapes. Array-y attrs are cast to objects so empty ones
     * JSON-encode as `{}` (the store treats them as keyed maps, not lists).
     */
    public function toBuilderArray(): array
    {
        return [
            'id'              => $this->id,
            'uid'             => $this->uid,
            'handle'          => $this->handle ?? '',
            'name'            => $this->name ?? '',
            'elementType'     => $this->elementType ?: Entry::class,
            'elementCriteria' => (object) ($this->elementCriteria ?? []),
            'endpoint'        => $this->endpoint,
            'itemEndpoint'    => $this->itemEndpoint,
            'siteEndpoints'   => $this->siteEndpoints,
            'offset'          => (object) ($this->offset ?? []),
            'processing'      => array_values($this->processing ?? []),
            'rootNode'        => $this->rootNode,
            'paginatorNode'   => $this->paginatorNode,
            'mappings'        => (object) ($this->mappings ?? []),
            'match'           => (object) ($this->match ?? []),
            'auth'            => (object) ($this->auth ?? []),
            'backup'          => (bool) $this->backup,
        ];
    }

    /**
     * Apply a builder JSON payload onto this link. Mirrors the shape
     * produced by {@see toBuilderArray()}. Unknown keys are silently
     * dropped — Yii's `setAttributes(..., $safeOnly = true)` would do this
     * for us, but we want to coerce a few fields (objects → arrays,
     * trimming strings) before they hit the model.
     */
    public function applyBuilderPayload(array $payload): void
    {
        $strOrNull = static fn(mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $this->handle = (string) ($payload['handle'] ?? $this->handle);
        $this->name = (string) ($payload['name'] ?? $this->name);
        $this->elementType = (string) ($payload['elementType'] ?? $this->elementType);

        $this->elementCriteria = (array) ($payload['elementCriteria'] ?? []);
        $this->endpoint = $strOrNull($payload['endpoint'] ?? null);
        $this->itemEndpoint = $strOrNull($payload['itemEndpoint'] ?? null);
        $this->setSiteEndpoints($payload['siteEndpoints'] ?? []);
        $this->offset = (array) ($payload['offset'] ?? []);
        $this->processing = array_values((array) ($payload['processing'] ?? []));
        $this->rootNode = $strOrNull($payload['rootNode'] ?? null);
        $this->paginatorNode = $strOrNull($payload['paginatorNode'] ?? null);
        $this->mappings = (array) ($payload['mappings'] ?? []);
        $this->match = (array) ($payload['match'] ?? []);
        $this->auth = (array) ($payload['auth'] ?? []);
        $this->backup = (bool) ($payload['backup'] ?? false);
    }

    /**
     * Mutates the given header / query arrays to add this link's auth
     * credentials. The actual rule per auth type lives on the strategy
     * classes in {@see \GlueAgency\Influx\auth}, dispatched via
     * {@see \GlueAgency\Influx\services\AuthService}.
     */
    public function applyAuth(array &$headers, array &$query): void
    {
        if (empty($this->auth)) {
            return;
        }

        $strategy = $this->authService()?->fromConfig($this->auth);

        if (! $strategy) {
            // Auth is configured but its type no longer resolves — e.g. a
            // third-party strategy was unregistered after the link was saved.
            // Fail loudly instead of firing the request unauthenticated.
            throw new InfluxException(
                "Link '{$this->handle}' has an unresolvable auth type '" . ($this->auth['type'] ?? '?') . "'.",
            );
        }

        $strategy->apply($headers, $query);
    }

    /**
     * Resolve the auth service via the plugin singleton, returning null when
     * the plugin isn't bootstrapped (e.g. in standalone unit tests that
     * never set auth on the link, so the lookup is never reached).
     */
    protected function authService(): ?\GlueAgency\Influx\services\AuthService
    {
        return Influx::getInstance()?->auth;
    }

    /**
     * @return list<array{site: string, endpoint: string}>
     */
    public function getSiteEndpoints(): array
    {
        return $this->siteEndpoints;
    }

    /**
     * Accepts either the canonical ordered list or a legacy `{handle: url}`
     * map (configs written before the list shape) and stores the list form.
     * Reached via `__set` on every external assignment — hydration from the
     * DB row, the builder payload, the Feed Me converter — so the model only
     * ever holds the normalized shape.
     */
    public function setSiteEndpoints(mixed $value): void
    {
        $this->siteEndpoints = self::normalizeSiteEndpoints($value);
    }

    /**
     * Coerce either stored shape into the canonical ordered list, dropping
     * rows missing a site handle or endpoint:
     *   - list:   [['site' => 'nl', 'endpoint' => '…'], …]
     *   - legacy: ['nl' => '…', 'en' => '…']
     *
     * @return list<array{site: string, endpoint: string}>
     */
    public static function normalizeSiteEndpoints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $key => $row) {
            if (is_array($row)) {
                $site = (string) ($row['site'] ?? (is_string($key) ? $key : ''));
                $endpoint = (string) ($row['endpoint'] ?? '');
            } else {
                $site = (string) $key;
                $endpoint = (string) $row;
            }

            $site = trim($site);
            $endpoint = trim($endpoint);

            if ($site === '' || $endpoint === '') {
                continue;
            }

            $list[] = ['site' => $site, 'endpoint' => $endpoint];
        }

        return $list;
    }

    /**
     * Site handles this link is configured to run for, in run order.
     *
     * @return list<string>
     */
    public function siteHandles(): array
    {
        return array_map(static fn(array $row): string => $row['site'], $this->siteEndpoints);
    }

    /**
     * Site handles a sync run iterates: the configured per-site endpoints, or
     * a single `[null]` meaning "the primary site" when none are configured.
     * The one place the "no sites = primary site" rule lives.
     *
     * @return list<string|null>
     */
    public function syncSiteHandles(): array
    {
        return $this->siteHandles() ?: [null];
    }

    /**
     * Endpoint configured for a specific site handle, or null when the site
     * has no dedicated endpoint (the caller then falls back to {@see $endpoint}).
     */
    public function endpointForSite(string $siteHandle): ?string
    {
        foreach ($this->siteEndpoints as $row) {
            if ($row['site'] === $siteHandle) {
                return $row['endpoint'];
            }
        }

        return null;
    }

    /**
     * Human-readable label for this link's element type — resolved through the
     * registered target's `friendlyName()`, falling back to the class's short
     * name when no target is registered for it.
     */
    public function elementTypeLabel(): string
    {
        return Influx::getInstance()->targets->friendlyNameFor($this->elementType);
    }

    /**
     * Section + entry-type display labels for the target — e.g. "Movies /
     * Feature" — resolved from the stored handles so the overview reads like
     * the CP rather than echoing raw handles. Null when no section criteria is
     * configured (the element type carries none, or it isn't set yet). Falls
     * back to the handle when a section/type has since been removed.
     */
    public function targetCriteriaLabel(): ?string
    {
        $criteria = $this->elementCriteria ?? [];
        $sectionHandle = $criteria['section'] ?? null;

        if (! $sectionHandle) {
            return null;
        }

        $section = Compat::getSectionByHandle($sectionHandle);
        $parts = [$section?->name ?? $sectionHandle];

        $typeHandle = $criteria['type'] ?? null;

        if ($typeHandle) {
            $typeName = null;

            if ($section) {
                foreach ($section->getEntryTypes() as $type) {
                    if ($type->handle === $typeHandle) {
                        $typeName = $type->name;

                        break;
                    }
                }
            }

            $parts[] = $typeName ?? $typeHandle;
        }

        return implode(' / ', $parts);
    }

    /**
     * Display names for the link's configured sites, for the overview — falls
     * back to the handle when a site has since been removed.
     *
     * @return string[]
     */
    public function siteLabels(): array
    {
        return array_map(
            static fn(string $handle): string => Craft::$app->getSites()->getSiteByHandle($handle)?->name ?? $handle,
            $this->siteHandles(),
        );
    }

    /**
     * Typed view over {@see self::$mappings}. Rebuilt lazily whenever the
     * raw array changes, so hydration (DB, builder payload) keeps assigning
     * the plain array and readers get {@see FieldMapping} objects.
     */
    public function getMappingCollection(): MappingCollection
    {
        if ($this->mappingCollection === null || $this->mappingCollectionSource !== $this->mappings) {
            $this->mappingCollection = MappingCollection::fromConfig($this->mappings);
            $this->mappingCollectionSource = $this->mappings;
        }

        return $this->mappingCollection;
    }

    /**
     * The unique key this item carries, read from the match attribute's
     * mapped node. Deliberately no `default` fallback — a match value must
     * come from the feed, or every item would match the same element.
     */
    public function matchValue(RemoteItem $item): mixed
    {
        $attr = $this->matchAttribute();

        return $attr ? $this->getMappingCollection()->get($attr)?->rawValue($item) : null;
    }

    public function matchAttribute(): ?string
    {
        return $this->match['attribute'] ?? null;
    }

    /**
     * Decide what a sync run should do with one remote item given its match
     * value and the element (if any) that was found for it. Used by both
     * {@see \GlueAgency\Influx\services\SynchronizationService::processItem()} for
     * the real run and {@see \GlueAgency\Influx\services\DebugService::debugItem()}
     * for the dry-run inspector, so both stay aligned on the rule.
     */
    public function decideAction(mixed $matchValue, ?ElementInterface $element): SyncDecision
    {
        if ($matchValue === null || $matchValue === '') {
            return SyncDecision::SKIP_NO_MATCH;
        }

        if ($element === null) {
            return in_array(self::PROCESSING_CREATE, $this->processing, true)
                ? SyncDecision::CREATE
                : SyncDecision::SKIP_NO_CREATE;
        }

        return in_array(self::PROCESSING_UPDATE, $this->processing, true)
            ? SyncDecision::UPDATE
            : SyncDecision::SKIP_NO_UPDATE;
    }
}
