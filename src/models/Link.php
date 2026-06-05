<?php

namespace TDM\Influx\models;

use Cake\Utility\Hash;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\helpers\StringHelper;
use TDM\Influx\auth\AuthStrategyInterface;
use TDM\Influx\Influx;

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

    /**
     * Decision outcomes returned by {@see self::decideAction()}. The
     * `DECISION_CREATE` and `DECISION_UPDATE` values intentionally share
     * strings with their processing-flag counterparts since they name the
     * same action; the `SKIP_*` values name the reason a sync would not
     * touch the element.
     */
    public const DECISION_CREATE          = self::PROCESSING_CREATE;
    public const DECISION_UPDATE          = self::PROCESSING_UPDATE;
    public const DECISION_SKIP_NO_MATCH   = 'skip:no-match';
    public const DECISION_SKIP_NO_CREATE  = 'skip:no-create';
    public const DECISION_SKIP_NO_UPDATE  = 'skip:no-update';

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
     * Endpoint pattern for syncing a single remote resource. Tokens like
     * `{id}` are substituted at sync-time. Used by the per-element
     * "Sync from remote" button.
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

    public function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['handle', 'name', 'elementType'], 'required'],
            [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => 'Handle must start with a letter and contain only letters, numbers, underscores, and dashes.'],
            [['endpoint', 'itemEndpoint'], 'string'],
            [['endpoint'], 'required', 'when' => fn(self $m) => empty($m->siteEndpoints), 'message' => 'Either an endpoint or at least one site endpoint is required.'],
            [['match'], 'validateMatch'],
            [['mappings'], 'validateMappings'],
            [['auth'], 'validateAuth'],
            [['processing'], 'each', 'rule' => ['in', 'range' => self::ALL_PROCESSING]],
        ]);
    }

    public function validateMatch(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_array($value) || empty($value['attribute'])) {
            $this->addError($attribute, 'Match must declare an `attribute`.');
            return;
        }

        // The match value is read from the node configured on the mapped
        // field, so the chosen match attribute must have an active mapping.
        $mappedNode = $this->mappings[$value['attribute']]['node'] ?? null;
        if (!$mappedNode) {
            $this->addError(
                $attribute,
                "Match attribute '{$value['attribute']}' needs a configured mapping with a source node.",
            );
        }
    }

    public function validateMappings(string $attribute): void
    {
        foreach ($this->$attribute as $handle => $config) {
            if (!is_array($config) || empty($config['type'])) {
                $this->addError($attribute, "Mapping for '{$handle}' is missing a `type`.");
            }
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
        if (!$strategy) {
            $known = $auth ? implode(', ', $auth->knownTypes()) : '?';
            $this->addError($attribute, "Auth type must be one of: {$known}.");
            return;
        }

        if (!$strategy->validate()) {
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
        if (!$this->uid) {
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

        return array_filter($config, function ($value) {
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
     * Mutates the given header / query arrays to add this link's auth
     * credentials. The actual rule per auth type lives on the strategy
     * classes in {@see \TDM\Influx\auth}, dispatched via
     * {@see \TDM\Influx\services\AuthService}.
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
    private function authService(): ?\TDM\Influx\services\AuthService
    {
        return Influx::getInstance()?->auth;
    }

    public function siteHandles(): array
    {
        return !empty($this->siteEndpoints) ? array_keys($this->siteEndpoints) : [];
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

    public function matchValue(array $item): mixed
    {
        $attr = $this->matchAttribute();
        $path = $attr ? ($this->mappings[$attr]['node'] ?? null) : null;
        return $path ? Hash::get($item, $path) : null;
    }

    public function matchAttribute(): ?string
    {
        return $this->match['attribute'] ?? null;
    }

    /**
     * Decide what a sync run should do with one remote item given its match
     * value and the element (if any) that was found for it. Used by both
     * {@see \TDM\Influx\services\SynchronizationService::processItem()} for
     * the real run and {@see \TDM\Influx\services\DebugService::debugItem()}
     * for the dry-run inspector, so both stay aligned on the rule.
     *
     * Returns one of: {@see self::DECISION_CREATE}, {@see self::DECISION_UPDATE},
     * {@see self::DECISION_SKIP_NO_MATCH}, {@see self::DECISION_SKIP_NO_CREATE},
     * {@see self::DECISION_SKIP_NO_UPDATE}.
     */
    public function decideAction(mixed $matchValue, ?ElementInterface $element): string
    {
        if ($matchValue === null || $matchValue === '') {
            return self::DECISION_SKIP_NO_MATCH;
        }
        if ($element === null) {
            return in_array(self::PROCESSING_CREATE, $this->processing, true)
                ? self::DECISION_CREATE
                : self::DECISION_SKIP_NO_CREATE;
        }
        return in_array(self::PROCESSING_UPDATE, $this->processing, true)
            ? self::DECISION_UPDATE
            : self::DECISION_SKIP_NO_UPDATE;
    }

}
