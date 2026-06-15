<?php

namespace GlueAgency\Influx\models;

use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use GlueAgency\Influx\enums\SyncDecision;
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

    /** @deprecated Use {@see SyncDecision::Create} — {@see self::decideAction()} returns the enum now. */
    public const DECISION_CREATE = self::PROCESSING_CREATE;
    /** @deprecated Use {@see SyncDecision::Update}. */
    public const DECISION_UPDATE = self::PROCESSING_UPDATE;
    /** @deprecated Use {@see SyncDecision::SkipNoMatch}. */
    public const DECISION_SKIP_NO_MATCH = 'skip:no-match';
    /** @deprecated Use {@see SyncDecision::SkipNoCreate}. */
    public const DECISION_SKIP_NO_CREATE = 'skip:no-create';
    /** @deprecated Use {@see SyncDecision::SkipNoUpdate}. */
    public const DECISION_SKIP_NO_UPDATE = 'skip:no-update';

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
     * Map of siteHandle => endpoint. When set, the link runs once per site,
     * fetching the localized payload and writing to that site's row on the
     * matched element.
     */
    public array $siteEndpoints = [];

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
        $mappedNode = $this->mappings[$value['attribute']]['node'] ?? null;

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
        $config = [
            'handle'          => $this->handle,
            'name'            => $this->name,
            'elementType'     => $this->elementType,
            'elementCriteria' => $this->elementCriteria,
            'endpoint'        => $this->endpoint,
            'itemEndpoint'    => $this->itemEndpoint,
            'siteEndpoints'   => $this->siteEndpoints,
            'auth'            => $this->auth,
            'rootNode'        => $this->rootNode,
            'paginatorNode'   => $this->paginatorNode,
            'match'           => $this->match,
            'mappings'        => $this->mappings,
            'processing'      => $this->processing,
            'offset'          => $this->offset,
            'backup'          => $this->backup,
        ];

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
            'siteEndpoints'   => (object) ($this->siteEndpoints ?? []),
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
        $this->siteEndpoints = (array) ($payload['siteEndpoints'] ?? []);
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
        $strategy = $this->authService()?->fromConfig($this->auth);
        $strategy?->apply($headers, $query);
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

    public function siteHandles(): array
    {
        return ! empty($this->siteEndpoints) ? array_keys($this->siteEndpoints) : [];
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
            return SyncDecision::SkipNoMatch;
        }

        if ($element === null) {
            return in_array(self::PROCESSING_CREATE, $this->processing, true)
                ? SyncDecision::Create
                : SyncDecision::SkipNoCreate;
        }

        return in_array(self::PROCESSING_UPDATE, $this->processing, true)
            ? SyncDecision::Update
            : SyncDecision::SkipNoUpdate;
    }
}
