<?php

namespace GlueAgency\Influx\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use DateTime;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\targets\ElementTargetInterface;

/**
 * An Influx link: one configured connection between Craft and an external
 * JSON API.
 *
 * Links are stored in Project Config under `influx.links.{uid}` — they
 * round-trip to YAML the same way sections, entry types, volumes, etc. do,
 * and obey the same `allowAdminChanges` gating.
 *
 * A plain state object: its serialized and presentation shapes live elsewhere,
 * so the model only holds attributes and the invariants over them.
 *  - {@see \GlueAgency\Influx\web\LinkBuilderSerializer} marshals the link to
 *    and from the LinkBuilder SPA's JSON wire shape (was `toBuilderArray()` /
 *    `applyBuilderPayload()`).
 *  - {@see \GlueAgency\Influx\web\LinkPresenter} resolves the human-readable
 *    labels the overview template renders (was `elementTypeLabel()`,
 *    `targetCriteriaLabel()`, `siteLabels()`).
 *  - {@see \GlueAgency\Influx\services\AuthService::requestAuth()} provides
 *    this link's auth headers + query for an outbound request.
 *  - {@see \GlueAgency\Influx\enums\SyncDecision::decide()} decides the sync
 *    action for a remote item.
 *  - {@see ElementTargetInterface} answers everything that depends on the
 *    element type a link points at — its claim scope ({@see claimScope()})
 *    included, and which {@see $elementCriteria} keys mean anything.
 */
class Link extends Model
{
    /**
     * The config fields a Link serialises to — its Project Config keys, which
     * are also its `influx_links` columns. THE single source of truth for
     * "what fields does a Link have": {@see getConfig()} builds from this and
     * {@see \GlueAgency\Influx\services\LinksService} maps the same fields onto
     * DB columns, rather than each re-listing them.
     *
     * Empty-shape contract: {@see getConfig()} strips empty values, so an empty
     * field is absent from Project Config and stored as NULL; the service reads
     * a missing JSON column back as `[]`;
     * {@see \GlueAgency\Influx\web\LinkBuilderSerializer::serialize()} casts the
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
        'totalCountNode',
        'pageCountNode',
        'match',
        'mappings',
        'processing',
        'offset',
        'backup',
        'sortOrder',
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

    public const CAST_STRING = 'string';
    public const CAST_NULLABLE_STRING = '?string';
    public const CAST_BOOL = 'bool';
    public const CAST_INT = 'int';
    public const CAST_NULLABLE_INT = '?int';
    public const CAST_NULLABLE_DATETIME = '?datetime';

    /**
     * How each non-JSON `influx_links` column coerces — every
     * {@see CONFIG_FIELDS} entry that isn't in {@see JSON_FIELDS}, plus the
     * runtime-only columns. The single declaration BOTH directions read
     * ({@see \GlueAgency\Influx\services\LinksService::columnValuesFromConfig()}
     * writing, {@see \GlueAgency\Influx\services\LinksService::linkFromRow()}
     * hydrating), so adding a scalar config field is a change to this file alone
     * — plus the migration — rather than to a hand-written list per direction.
     *
     * Some drivers hand boolean / int columns back as strings, which is why the
     * read side coerces at all; the write side coerces so callers (PC payloads,
     * hand-written YAML) don't have to.
     */
    public const COLUMN_CASTS = [
        'handle'         => self::CAST_STRING,
        'name'           => self::CAST_STRING,
        'elementType'    => self::CAST_STRING,
        'endpoint'       => self::CAST_NULLABLE_STRING,
        'itemEndpoint'   => self::CAST_NULLABLE_STRING,
        'rootNode'       => self::CAST_NULLABLE_STRING,
        'paginatorNode'  => self::CAST_NULLABLE_STRING,
        'totalCountNode' => self::CAST_NULLABLE_STRING,
        'pageCountNode'  => self::CAST_NULLABLE_STRING,
        'backup'         => self::CAST_BOOL,
        'sortOrder'      => self::CAST_NULLABLE_INT,
        'id'             => self::CAST_INT,
        'lastRunAt'      => self::CAST_NULLABLE_DATETIME,
        'lastLogId'      => self::CAST_NULLABLE_INT,
        'dateCreated'    => self::CAST_NULLABLE_DATETIME,
        'dateUpdated'    => self::CAST_NULLABLE_DATETIME,
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
     *
     * Which keys are meaningful is the target's business, not the model's: each
     * one declares them through
     * {@see ElementTargetInterface::criteriaKeys()} and owns the key names as
     * constants (see {@see \GlueAgency\Influx\targets\EntryTarget::CRITERIA_SECTION}).
     * Read through {@see criterion()} rather than indexing this directly, so the
     * key literals stay in that one place.
     *
     * @var array<string, string>
     */
    public array $elementCriteria = [];

    /**
     * Default endpoint when no per-site endpoint is configured.
     */
    public ?string $endpoint = null;

    /**
     * Endpoint pattern for syncing a single remote resource. Used by the
     * per-element "Sync from remote" button. Tokens substituted at sync-time
     * are built by {@see \GlueAgency\Influx\services\EndpointTokensService::tokensForElement()}:
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
     * intact. Always assigned through {@see setSiteEndpoints()} so the model
     * only ever holds the normalized shape.
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
     * Hash dot-path (in the response, not the item) to the total item count,
     * when the feed reports one — lets the sync show a real progress %.
     */
    public ?string $totalCountNode = null;

    /**
     * Hash dot-path (in the response) to the total page count, when the feed
     * reports one — drives page-based progress and page-per-step batching.
     */
    public ?string $pageCountNode = null;

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
     * Allowed actions. Subset of {@see ProcessingAction::values()}; a fresh
     * link starts on {@see ProcessingAction::defaults()}, assigned in the
     * constructor.
     *
     * @var list<string>
     */
    public array $processing = [];

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

    /**
     * 1-based manual position in the Links overview. Assigned on first save
     * ({@see \GlueAgency\Influx\services\LinksService::saveLink()}) and
     * rewritten by drag-to-sort
     * ({@see \GlueAgency\Influx\services\LinksService::saveOrder()}). Null on a
     * link that has never been saved; {@see \GlueAgency\Influx\services\LinksService::createQuery()}
     * falls back to name order so those still sort deterministically.
     */
    public ?int $sortOrder = null;

    /**
     * When this link last started a sync run — runtime state, NOT a config
     * field (absent from {@see CONFIG_FIELDS}, so it never reaches Project
     * Config). Survives log deletion, so the overview can show "last run" even
     * after the run's log is gone. Set by
     * {@see \GlueAgency\Influx\services\LinksService::recordRun()}.
     */
    public ?DateTime $lastRunAt = null;

    /**
     * Soft pointer to the log of the last run, for quick access from the
     * overview. Nulled when that log is deleted
     * ({@see \GlueAgency\Influx\services\LinksService::forgetDeletedLogs()}), so
     * a non-null value means the log still exists. Null when the last run
     * wasn't logged (logging disabled). Runtime state, not config.
     */
    public ?int $lastLogId = null;

    /**
     * Row timestamps, written by {@see \GlueAgency\Influx\services\LinksService::saveLink()}
     * on every save. Runtime state like {@see $lastRunAt} — absent from
     * {@see CONFIG_FIELDS}, so neither reaches Project Config — and null on a
     * link that has never been saved. Read by the builder's details sidebar.
     */
    public ?DateTime $dateCreated = null;

    public ?DateTime $dateUpdated = null;

    /** Memoized typed view over $mappings — see {@see getMappingCollection()}. */
    protected ?MappingCollection $mappingCollection = null;

    /** The raw $mappings array the memo was built from, for staleness checks. */
    protected ?array $mappingCollectionSource = null;

    /**
     * Seeds the default processing policy. It can't be a property default:
     * those must be constant expressions, which can't reach
     * {@see ProcessingAction::defaults()} — and duplicating the values here is
     * exactly what the enum owns. A `processing` key in `$config` still wins,
     * since {@see \yii\base\BaseObject::__construct()} applies it after this.
     *
     * @param array $config
     */
    public function __construct($config = [])
    {
        $this->processing = ProcessingAction::defaults();

        parent::__construct($config);
    }

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['handle', 'name', 'elementType'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and dashes.'],
            [['handle'], 'string', 'max' => 100],
            [['name'], 'string', 'max' => 255],
            [['endpoint', 'itemEndpoint'], 'string'],
            [['endpoint'], 'required', 'when' => fn(self $m) => empty($m->siteEndpoints), 'message' => 'Either an endpoint or at least one site endpoint is required.'],
            [['siteEndpoints'], 'validateSiteEndpoints'],
            [['match'], 'validateMatch'],
            [['auth'], 'validateAuth'],
            [['processing'], 'each', 'rule' => ['in', 'range' => ProcessingAction::values()]],
        ]);
    }

    /**
     * Whether this link's processing policy permits an action — the one reader
     * of {@see $processing} the sync engine asks, so no caller repeats the
     * `in_array($action->value, ..., true)` membership test. Sugar over the same
     * stored list: neither the wire nor the stored shape changes.
     */
    public function allows(ProcessingAction $action): bool
    {
        return in_array($action->value, $this->processing, true);
    }

    /**
     * Swap the missing-element policies to match the link's endpoint shape,
     * returning the migrations performed as `[['from' => …, 'to' => …], …]`
     * (empty when nothing changed). Idempotent, so calling it twice is safe.
     *
     * With site-specific endpoints a run owns one site's rows, so the global
     * `disable`/`delete` policies (which touch the canonical element across
     * every site) are swapped to their {@see ProcessingAction::siteCounterpart()};
     * without site endpoints the `-for-site` policies have no site to scope to
     * and are swapped back to their {@see ProcessingAction::globalCounterpart()}.
     * Rather than reject a mismatched combo on save, we heal it here and let the
     * caller tell the user what changed. A value the enum doesn't know passes
     * through untouched.
     *
     * A swap can collide with a policy that was already in its target form, so
     * the result is deduped, keeping first-seen order.
     *
     * @return list<array{from: string, to: string}>
     */
    public function migrateProcessingForEndpointShape(): array
    {
        if (! is_array($this->processing)) {
            return [];
        }

        $hasSiteEndpoints = ! empty($this->siteEndpoints);

        $migrations = [];
        $migrated = [];

        foreach ($this->processing as $action) {
            $case = ProcessingAction::tryFrom((string) $action);
            $counterpart = $hasSiteEndpoints ? $case?->siteCounterpart() : $case?->globalCounterpart();
            $to = $counterpart?->value ?? $action;

            if ($to !== $action) {
                $migrations[] = ['from' => $action, 'to' => $to];
            }

            $migrated[] = $to;
        }

        $this->processing = array_values(array_unique($migrated));

        return $migrations;
    }

    /**
     * Reject site-specific endpoints on an element type whose target doesn't
     * support multi-site (Users are global, non-localizable). The builder hides
     * the site-specific controls for such types, so this only bites config
     * edited by hand or carried over from a different element type — the
     * server-side backstop for {@see \GlueAgency\Influx\targets\ElementTargetInterface::supportsMultiSite()}.
     */
    public function validateSiteEndpoints(string $attribute): void
    {
        if (empty($this->siteEndpoints)) {
            return;
        }

        $target = $this->target();

        if ($target && ! $target::supportsMultiSite()) {
            $this->addError($attribute, $target::friendlyName() . ' links can’t use site-specific endpoints.');
        }
    }

    /**
     * The match value is always read from the matched field's mapping node, so
     * the attribute needs an active mapping with a source node — hence the
     * second check.
     */
    public function validateMatch(string $attribute): void
    {
        $value = $this->$attribute;

        if (! is_array($value) || empty($value['attribute'])) {
            $this->addError($attribute, 'Match must declare an `attribute`.');

            return;
        }

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
     * Make sure every link has a UID. Called by
     * {@see \GlueAgency\Influx\services\LinksService::saveLink()} before writing
     * to Project Config.
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

        return array_filter($config, static fn(mixed $value): bool => ! self::isEmptyConfigValue($value));
    }

    /**
     * THE empty-shape rule for stored config: a null, an empty string, a false
     * flag or an empty array carries nothing, so it's left out of Project Config
     * (and stored as NULL in the DB column) rather than written as noise.
     *
     * {@see getConfig()} is the final gate that applies it to a link's own
     * fields; {@see FieldMapping::toConfig()} applies the same rule one level
     * down, per mapping slot, so a mapping converges on the same shape before it
     * ever reaches the gate.
     */
    public static function isEmptyConfigValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return true;
        }

        return is_array($value) && $value === [];
    }

    /**
     * Coerce one column value per its {@see COLUMN_CASTS} declaration. An
     * undeclared column passes through untouched (`uid` needs no coercion).
     */
    public static function castColumnValue(string $column, mixed $value): mixed
    {
        return match (self::COLUMN_CASTS[$column] ?? null) {
            self::CAST_STRING            => (string) ($value ?? ''),
            self::CAST_NULLABLE_STRING   => $value === null ? null : (string) $value,
            self::CAST_BOOL              => ! empty($value),
            self::CAST_INT               => (int) $value,
            self::CAST_NULLABLE_INT      => $value === null ? null : (int) $value,
            self::CAST_NULLABLE_DATETIME => empty($value) ? null : (DateTimeHelper::toDateTime($value) ?: null),
            default                      => $value,
        };
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
     * Resolve the targets service via the plugin singleton, returning null when
     * the plugin isn't bootstrapped (e.g. standalone unit tests) — {@see target()}
     * then hands back null and its callers fall back.
     */
    protected function targetsService(): ?\GlueAgency\Influx\services\TargetsService
    {
        return Influx::getInstance()?->targets;
    }

    /**
     * @return list<array{site: string, endpoint: string}>
     */
    public function getSiteEndpoints(): array
    {
        return $this->siteEndpoints;
    }

    /**
     * Normalizes the canonical ordered list before storing it. Reached via
     * `__set` on every external assignment — hydration from the DB row, the
     * builder payload, the Feed Me converter — so the model only ever holds
     * the normalized shape.
     */
    public function setSiteEndpoints(mixed $value): void
    {
        $this->siteEndpoints = self::normalizeSiteEndpoints($value);
    }

    /**
     * Normalize the canonical ordered list — `[['site' => 'nl', 'endpoint'
     * => '…'], …]` — trimming values and dropping rows missing a site handle
     * or endpoint.
     *
     * @return list<array{site: string, endpoint: string}>
     */
    public static function normalizeSiteEndpoints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $site = trim((string) ($row['site'] ?? ''));
            $endpoint = trim((string) ($row['endpoint'] ?? ''));

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
     * One {@see $elementCriteria} value, or null when the link doesn't scope on
     * that key. THE reader for stored criteria: callers pass the owning target's
     * key constant (`EntryTarget::CRITERIA_SECTION`) instead of a literal, so the
     * key names live with the target that declares them.
     *
     * An empty string is no criterion — the builder writes an unset dropdown as
     * null, {@see getConfig()} strips it, and a hand-edited `section: ''` is a
     * missing section rather than a section whose handle is blank.
     */
    public function criterion(string $key): ?string
    {
        $value = $this->elementCriteria[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * The link's STRUCTURAL claim scope — a canonical, comparable description
     * of which elements this link manages, used to warn about two links owning
     * the same elements ({@see overlaps()}).
     *
     * Shape: `['type' => <elementType FQCN>, 'cells' => <string[]>]`. The `type`
     * key ensures links to different element types never overlap; the `cells` set
     * is what two same-type links intersect on, and comes from the target —
     * partitioning an element type is its business, not the model's
     * ({@see ElementTargetInterface::claimCells()}). An unresolvable target (no
     * registered target for the type, or the plugin isn't bootstrapped) falls back
     * to the broadest sentinel, so an unknown type is treated as "claims
     * everything" rather than as claiming nothing.
     *
     * @return array{type: string, cells: list<string>}
     */
    public function claimScope(): array
    {
        $target = $this->target();

        return [
            'type'  => ltrim($this->elementType, '\\'),
            'cells' => $target ? $target->claimCells($this) : [ElementTargetInterface::CLAIM_ALL],
        ];
    }

    /**
     * Whether this link and another manage an overlapping set of elements:
     * same element type and intersecting {@see claimScope()} cells.
     */
    public function overlaps(self $other): bool
    {
        $mine = $this->claimScope();
        $theirs = $other->claimScope();

        if ($mine['type'] !== $theirs['type']) {
            return false;
        }

        return array_intersect($mine['cells'], $theirs['cells']) !== [];
    }

    /**
     * The target registered for this link's element type, or null when there is
     * none — including when the plugin isn't bootstrapped (standalone unit
     * tests). Isolated as a seam so the target-dependent members stay testable
     * against a stub; every caller must handle the null.
     */
    protected function target(): ?ElementTargetInterface
    {
        return $this->targetsService()?->forLink($this);
    }
}
