<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\web\twig\variables\Cp;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\web\LinkBuilderSerializer;
use Throwable;
use yii\web\NotFoundHttpException;

/**
 * Orchestrates the data that the LinkBuilder Vue SPA needs to render and
 * persist a link. Sits *above* {@see LinksService}: it transforms back and
 * forth between the JSON shape the SPA speaks and the model/Project-Config
 * shape that the storage layer expects.
 *
 * The controller layer ({@see \GlueAgency\Influx\controllers\LinkBuilderController})
 * stays thin — request-method enforcement, JSON in/out, and delegating
 * everything else here. Keeping the heavy lifting in a service means
 * console commands, queue jobs, or other plugins can call the same surface
 * without spinning up a Yii request.
 */
class LinkBuilderService extends Component
{
    /**
     * Marshals a {@see Link} to / from the SPA's JSON wire shape — the shared
     * instance this service serializes bootstrap / save payloads through.
     */
    protected LinkBuilderSerializer $serializer;

    public function init(): void
    {
        parent::init();

        $this->serializer = new LinkBuilderSerializer();
    }

    /**
     * Initial payload the SPA needs to mount. Returns the link being edited
     * (or a fresh draft when `$id` is null) plus a small bundle of
     * always-needed options. Heavier per-tab data is fetched lazily via
     * dedicated endpoints so this stays light.
     *
     * @return array{
     *   link: array,
     *   options: array,
     *   meta: array,
     * }
     */
    public function bootstrap(?int $id, bool $readOnly): array
    {
        $plugin = Influx::getInstance();
        $isNew = ($id === null);

        if ($isNew) {
            $link = new Link([
                'elementType' => Entry::class,
                'processing'  => [Link::PROCESSING_CREATE, Link::PROCESSING_UPDATE],
            ]);
        } else {
            $link = $plugin->links->getLinkById($id);

            if (! $link) {
                throw new NotFoundHttpException("Link {$id} not found.");
            }
        }

        return [
            'link'    => $this->serializer->toArray($link),
            'options' => [
                'elementTypes'      => $this->elementTypeOptions(),
                'sections'          => $this->sectionOptions(),
                'sectionEntryTypes' => $this->sectionEntryTypes(),
                'sites'             => $this->siteOptions(),
                'processingActions' => $this->processingActionOptions(),
                'authTypes'         => $this->authTypeOptions(),
                'authStrategies'    => $this->authStrategyDefinitions(),
            ],
            'meta' => [
                'isNew'         => $isNew,
                'readOnly'      => $readOnly,
                'handle'        => $link->handle ?: null,
                'csrfTokenName' => Craft::$app->getRequest()->csrfParam,
                'csrfToken'     => Craft::$app->getRequest()->getCsrfToken(),
                // Environment-variable + Craft-alias suggestions for the
                // endpoint pickers. Same shape as `tokenSuggestions` but
                // each item is `type: 'text'` — selecting one drops the
                // literal `$NAME` / `@alias` into the URL, not a chip.
                'envSuggestions' => $this->envAndAliasSuggestions(),
            ],
        ];
    }

    /**
     * Persist a link from the SPA payload. Returns the saved link's
     * serialized state on success, or the unified failure envelope
     * (`{success: false, message, errors}`) on validation failure — never throws
     * for validation; the controller turns the envelope into a 400.
     *
     * @param array $payload Raw JSON body posted by the SPA.
     * @return array{success: true, link: array}|array{success: false, message: string, errors: array<string, string[]>}
     */
    public function save(array $payload): array
    {
        $plugin = Influx::getInstance();

        $uid = $payload['uid'] ?? null;
        $link = $uid
            ? ($plugin->links->getLinkByUid($uid) ?? new Link())
            : new Link();

        $this->serializer->apply($link, $payload);

        if (! $plugin->links->saveLink($link)) {
            return [
                'success' => false,
                'message' => Craft::t('influx', 'Couldn’t save link.'),
                'errors'  => $link->getErrors(),
            ];
        }

        return ['success' => true, 'link' => $this->serializer->toArray($link)];
    }

    /**
     * Mappable fields for a given element type / criteria combination,
     * grouped the same way the Mapping tab renders them. Drives the
     * reactive update when the user changes the section or entry-type
     * dropdowns in the SPA.
     *
     * @return array{
     *   fields: list<array>,
     *   groups: list<array>,
     *   matchOptions: list<array{label: ?string, kind: ?string, options: list<array{value: string, label: string}>}>,
     * }
     */
    public function mappableFields(string $elementType, array $criteria): array
    {
        $stub = new Link(['elementType' => $elementType, 'elementCriteria' => $criteria]);
        $target = Influx::getInstance()->targets->forLink($stub);

        $fields = $target ? $target->getMappableFields($stub) : [];
        $groups = $this->groupMappableFields($fields);

        // Grouped for the SPA's SearchableSelect: the clear sentinel renders
        // as a plain row, the target's matchable natives (unique identifiers
        // only — not every mappable attribute) under the element type's
        // display name (green `element` chips), custom fields under
        // "Fields" (gray).
        $nativeOptions = $target ? $target->matchableNativeAttributes($stub) : [];
        $fieldOptions = [];

        foreach ($fields as $f) {
            if (! empty($f['native'])) {
                continue;
            }
            $fieldOptions[] = [
                'value' => $f['handle'],
                'label' => "{$f['name']} ({$f['handle']})",
            ];
        }

        $matchOptions = [
            [
                'label'   => null,
                'kind'    => null,
                'options' => [['value' => '', 'label' => Craft::t('influx', '— select a field —')]],
            ],
        ];

        if ($nativeOptions) {
            $matchOptions[] = [
                'label'   => $target ? $target::friendlyName() : Craft::t('influx', 'Native'),
                'kind'    => 'element',
                'options' => $nativeOptions,
            ];
        }

        if ($fieldOptions) {
            $matchOptions[] = [
                'label'   => Craft::t('influx', 'Fields'),
                'kind'    => 'fields',
                'options' => $fieldOptions,
            ];
        }

        return ['fields' => $fields, 'groups' => $groups, 'matchOptions' => $matchOptions];
    }

    /**
     * Endpoint-token-picker suggestions for a given element type / criteria.
     * Thin wrapper around {@see EndpointTokensService::suggestions()} with
     * the right Link stub.
     */
    public function endpointTokenSuggestions(string $elementType, array $criteria): array
    {
        $stub = new Link(['elementType' => $elementType, 'elementCriteria' => $criteria]);

        return Influx::getInstance()->endpointTokens->suggestions($stub);
    }

    /**
     * Fetch the configured endpoint and report rootNode / paginatorNode
     * candidates + sample item structure. Drives the Pagination tab's
     * "Fetch sample" button. Operates on a transient Link built from the
     * SPA's current state, so users can fetch a sample mid-edit without
     * having to save first.
     *
     * Returns `{success: true, report: ...}` on success or `{success: false, message: ...}`
     * on failure (network, bad JSON, missing rootNode, ...). Never throws — the
     * UI surface needs the message inline.
     *
     * @return array{success: true, report: array}|array{success: false, message: string}
     */
    public function sample(array $payload): array
    {
        $endpoint = $this->emptyToNull($payload['endpoint'] ?? null);

        if (! $endpoint) {
            return ['success' => false, 'message' => Craft::t('influx', 'Set a list endpoint first.')];
        }

        $link = new Link([
            'handle'        => 'sample',
            'name'          => 'sample',
            'elementType'   => 'sample',
            'endpoint'      => $endpoint,
            'rootNode'      => $this->emptyToNull($payload['rootNode'] ?? null),
            'paginatorNode' => $this->emptyToNull($payload['paginatorNode'] ?? null),
            'auth'          => (array) ($payload['auth'] ?? []),
        ]);

        try {
            $report = Influx::getInstance()->data->inspect($link);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'report' => $report];
    }

    protected function emptyToNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /**
     * Render Craft's `forms/elementSelect` partial server-side and return
     * the HTML + the JS settings the SPA needs to bring it to life. The
     * Vue host inserts the HTML into a ref'd <div> and instantiates
     * `Craft.BaseElementSelectInput(jsSettings)` itself — `registerJs:false`
     * keeps the partial from emitting its own init into the page-level
     * JS register, which would never fire in a SPA load anyway.
     *
     * @param string $elementType FQCN of the target element type.
     * @param int[]  $ids         Currently-selected element ids.
     * @return array{html: string, jsSettings: array}
     */
    public function renderElementSelect(string $elementType, array $ids): array
    {
        $elements = [];

        foreach ($ids as $id) {
            $el = Craft::$app->getElements()->getElementById((int) $id, $elementType);

            if ($el) {
                $elements[] = $el;
            }
        }

        $hostId = 'influx-el-' . StringHelper::randomString(8);

        $renderArgs = [
            'id'             => $hostId,
            'name'           => null,
            'elementType'    => $elementType,
            'elements'       => $elements,
            'sources'        => '*',
            'limit'          => 1,
            'single'         => true,
            'sortable'       => false,
            'showActionMenu' => false,
            'registerJs'     => false,
        ];

        $html = Craft::$app->getView()->renderTemplate(
            '_includes/forms/elementSelect',
            $renderArgs,
        );

        // Mirror the jsSettings the Twig template builds at the tail.
        // BaseElementSelectInput tolerates omitting most of these, but
        // the explicit ones below match what the standard CP fields use.
        $jsSettings = [
            'id'               => $hostId,
            'name'             => null,
            'elementType'      => $elementType,
            'sources'          => '*',
            'limit'            => 1,
            'single'           => true,
            'sortable'         => false,
            'showActionMenu'   => false,
            'viewMode'         => 'list',
            'defaultPlacement' => 'end',
            'modalSettings'    => (object) [],
        ];

        return ['html' => $html, 'jsSettings' => $jsSettings];
    }

    /**
     * Strings the SPA passes through its `$t` helper. Registered with
     * Craft's View layer so `Craft.translations.influx` is populated for
     * any locale that ships a translations file. Without a file, `Craft.t`
     * falls back to the original string — the wrap is forward-compatible.
     *
     * Keep this list aligned with the strings the Vue templates wrap in
     * `$t(...)`. If a string only appears server-side (e.g. an option
     * label built by {@see self::elementTypeOptions()}), it's already
     * routed through `Craft::t()` and doesn't need to be listed here.
     *
     * @return string[]
     */
    public function translatableStrings(): array
    {
        return [
            // LinkBuilder.vue
            'Loading…',
            'Couldn’t load this link:',
            'Check the Craft logs for the full stack trace, or reload to retry.',

            // HeaderActions.vue
            'Save', 'Saving…', 'More save options', 'Save and continue editing',
            'Fetch sample', 'Refetch sample', 'Fetching…', 'Fetching sample…',
            'Set a Base Endpoint on the General tab first',
            'Last attempt failed: {message}',
            'Last fetched from {url}',
            'Hit the configured endpoint and inspect the response',

            // GeneralTab.vue
            'Name', 'What this link will be called in the control panel.',
            'Handle', 'Identifier used in console commands and event keys.',
            'Element', 'Element type', 'Section', 'Entry type',
            '— select —',
            'Endpoint', 'Base Endpoint',
            'JSON URL, or an <code>@alias</code> pointing to a local JSON file.',
            'Sliding-window presets',
            'Enable if the external service supports synchronisation by offset.',
            'Each preset becomes a button on the link page and a <code>--offset=KEY</code> option on the console command.',
            'Resource Endpoint supported', 'Resource Endpoint',
            'URL pattern for the per-element "Sync from remote" button. Type the URL and use the picker to inline a token where the cursor is — chips show you where each placeholder lives.',
            'Site-specific endpoints',
            'Enable if the external service supports resource localisation.',
            'The link runs once per listed site and writes localized data to the same canonical element.',
            'Processing actions',

            // PaginationTab.vue
            'Use the <strong>Fetch sample</strong> action in the page header to call your configured endpoint and populate the dropdowns below from the discovered JSON nodes.',
            'Sample failed:',
            'Root node',
            'Dot-path to the iterable list inside the response. Leave blank if the response itself is a JSON array.',
            '— response root —',
            'Paginator node',
            'Dot-path to the next-page URL for cursor pagination. Leave blank if the response is single-page.',
            '— no paginator —',
            'Sample item', 'First item under',

            // MappingTab.vue
            'For each destination field, pick the JSON node it should read from. Add a default value to fall back to when the node is missing or empty. Use the “Fetch sample” button on the Pagination tab to populate the dropdowns with discovered JSON nodes.',
            'Couldn’t load mappable fields:',
            'Loading mappable fields…',
            'Pick an element type (and a section, for entries) on the General tab to see destination fields here.',
            'Match key',
            'How Influx pairs a remote item with an existing element across sites. The match value is read from the field’s configured source node above, so make sure that field has a mapping.',
            'Match attribute',
            'The element field whose value uniquely identifies a record across syncs. Typically a custom plain-text field like <code>importId</code>.',

            // MappingGroup.vue
            'Fields with an active source node', 'mapped',
            'Fields whose saved source node is no longer in the fetched sample', 'missing',
            'Total fields in this group',
            'Field', 'Source node', 'Default value',

            // MappingRow.vue
            'Saved source node is no longer in the fetched sample. Pick a new one or clear the mapping.',
            'missing mapping', '— no mapping —',

            // MatrixFields.vue / ElementSubFields.vue (shared sub-field rows)
            'Sub-fields with an active source node',
            'Sub-fields whose saved source node is no longer in the fetched sample',
            'Total sub-fields in this group',
            '— use default —', 'Nodes',
            'Search nodes…',
            'Run “Fetch sample” to discover nodes.',
            'This block type has no mappable sub-fields.',

            // AuthTab.vue
            'Authentication type',
            'How Influx should authenticate against the remote API.',
            'No SPA-side schema is registered for auth type',

            // SettingsTab.vue
            'Take a DB backup before every run',
            'Off by default. Mainly useful for destructive processing actions.',

            // OffsetPresetsTable.vue
            'Handle', 'Since', 'Query param', 'Date format',
            'Anything <code>DateTime::modify</code> accepts.',
            'Anything <code>DateTime::format</code> accepts.',
            'e.g. last24h',
            'Delete row {idx}',
            'Add a preset',

            // SiteEndpointsTable.vue
            'Site', 'Endpoint URL', '— select a site —', 'Add a site endpoint',

            // TokenizedInput.vue
            'Remove {name}', 'Filter tokens…', 'No matches for',
        ];
    }

    /**
     * Wrap Craft's {@see \craft\web\twig\variables\Cp::getEnvSuggestions()}
     * into the picker's group shape, marking every entry `type: 'text'` so
     * the TokenizedInput inserts them as literal string segments (e.g.
     * `$API_BASE`, `@webroot`) instead of as chips. Env vars get
     * `kind: 'env'`, aliases `kind: 'alias'` — distinct accent colors in
     * the picker preview help users tell them apart at a glance.
     *
     * @return list<array{kind: string, label: string, data: list<array{name: string, hint?: string, type: string}>}>
     */
    protected function envAndAliasSuggestions(): array
    {
        $cp = new Cp();
        $raw = $cp->getEnvSuggestions(true);

        $out = [];

        foreach ($raw as $group) {
            $items = [];

            foreach (($group['data'] ?? []) as $item) {
                $name = (string) ($item['name'] ?? '');

                if ($name === '') {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'hint' => (string) ($item['hint'] ?? ''),
                    'type' => 'text',
                ];
            }

            if (! $items) {
                continue;
            }

            // Slugify the group by inspecting the first item's prefix —
            // Craft's `getEnvSuggestions` returns env vars first, aliases
            // second; the prefix is a more reliable marker than the
            // (translated) label.
            $kind = str_starts_with($items[0]['name'], '@') ? 'alias' : 'env';
            $out[] = [
                'kind'  => $kind,
                'label' => $group['label'] ?? Craft::t('influx', 'Environment'),
                'data'  => $items,
            ];
        }

        return $out;
    }


    // ------------------------------------------------------------------
    //  Option builders. Internal — the SPA only sees their output
    //  via `bootstrap()`.
    // ------------------------------------------------------------------

    protected function elementTypeOptions(): array
    {
        $out = [];

        foreach (Influx::getInstance()->targets->all() as $target) {
            $out[] = [
                'value' => $target::elementType(),
                'label' => $target::friendlyName(),
            ];
        }

        return $out;
    }

    protected function sectionOptions(): array
    {
        $out = [['value' => '', 'label' => Craft::t('influx', '— select —')]];

        foreach (Compat::getAllSections() as $section) {
            $out[] = ['value' => $section->handle, 'label' => $section->name];
        }

        return $out;
    }

    protected function sectionEntryTypes(): array
    {
        $out = [];

        foreach (Compat::getAllSections() as $section) {
            $types = [];

            foreach ($section->getEntryTypes() as $type) {
                $types[$type->handle] = $type->name;
            }
            $out[$section->handle] = $types;
        }

        return $out;
    }

    protected function siteOptions(): array
    {
        $out = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $out[] = ['value' => $site->handle, 'label' => $site->name];
        }

        return $out;
    }

    protected function processingActionOptions(): array
    {
        return [
            ['value' => Link::PROCESSING_CREATE,           'label' => Craft::t('influx', 'Create new elements')],
            ['value' => Link::PROCESSING_UPDATE,           'label' => Craft::t('influx', 'Update existing elements')],
            ['value' => Link::PROCESSING_DISABLE,          'label' => Craft::t('influx', 'Disable elements missing from the feed')],
            ['value' => Link::PROCESSING_DELETE,           'label' => Craft::t('influx', 'Delete elements missing from the feed')],
            ['value' => Link::PROCESSING_DELETE_FOR_SITE,  'label' => Craft::t('influx', 'Delete the site-specific row only')],
        ];
    }

    protected function authTypeOptions(): array
    {
        $out = [['value' => '', 'label' => Craft::t('influx', '— none —')]];

        foreach (Influx::getInstance()->auth->strategies() as $type => $class) {
            $out[] = ['value' => $type, 'label' => Craft::t('influx', $class::label())];
        }

        return $out;
    }

    /**
     * Per-strategy form schemas consumed by the SPA's Authentication tab.
     * Strategies declare {@see \GlueAgency\Influx\helpers\BuilderSchema} nodes
     * natively via {@see \GlueAgency\Influx\auth\AuthStrategyInterface::editSchema()}
     * — the same vocabulary the mapping extras use — so this is pure
     * aggregation. Strategies with no extra fields (empty schema) are
     * skipped; the SPA falls back to "no schema" messaging if a stored
     * link is using an auth type that's not registered.
     *
     * @return list<array{type: string, schema: list<array>}>
     */
    protected function authStrategyDefinitions(): array
    {
        $out = [];

        foreach (Influx::getInstance()->auth->strategies() as $type => $class) {
            $schema = $class::editSchema();

            if (empty($schema)) {
                continue;
            }
            $out[] = ['type' => $type, 'schema' => $schema];
        }

        return $out;
    }

    /** Group flat mappable fields by their `group` label. */
    protected function groupMappableFields(array $fields): array
    {
        $byLabel = [];

        foreach ($fields as $field) {
            $label = $field['group'] ?? Craft::t('influx', 'Other');

            if (! isset($byLabel[$label])) {
                $byLabel[$label] = ['label' => $label, 'fields' => []];
            }
            $byLabel[$label]['fields'][] = $field;
        }

        return array_values($byLabel);
    }
}
