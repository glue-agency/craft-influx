<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\fields\Icon as CraftIconField;
use craft\helpers\Cp;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\web\LinkBuilderOptionsPresenter;
use GlueAgency\Influx\web\LinkBuilderSerializer;
use Throwable;

/**
 * Orchestrates the data that the LinkBuilder Vue SPA needs to render and
 * persist a link. Sits *above* {@see LinksService}: it transforms back and
 * forth between the JSON shape the SPA speaks and the model/Project-Config
 * shape that the storage layer expects.
 *
 * Marshalling only. The option lists and view-model blocks it hands out are
 * built by {@see LinkBuilderOptionsPresenter}, the link's own wire shape by
 * {@see LinkBuilderSerializer}, and the SPA's UI strings live in
 * {@see \GlueAgency\Influx\web\LinkBuilderTranslations} — so nothing here
 * renders or hard-codes presentation.
 *
 * The controller layer ({@see \GlueAgency\Influx\controllers\LinkBuilderController})
 * stays thin — request-method enforcement, JSON in/out, HTTP status decisions
 * (including the 404 for a link that doesn't exist), and delegating everything
 * else here. Keeping the heavy lifting in a service means console commands,
 * queue jobs, or other plugins can call the same surface without spinning up a
 * Yii request — which is also why nothing here throws an HTTP exception.
 */
class LinkBuilderService extends Component
{
    /**
     * Marshals a {@see Link} to / from the SPA's JSON wire shape — the shared
     * instance this service serializes bootstrap / save payloads through.
     */
    protected LinkBuilderSerializer $serializer;

    /**
     * Builds the option lists the bootstrap payload carries.
     */
    protected LinkBuilderOptionsPresenter $options;

    public function init(): void
    {
        parent::init();

        $this->serializer = new LinkBuilderSerializer();
        $this->options = new LinkBuilderOptionsPresenter();
    }

    /**
     * Initial payload the SPA needs to mount. Returns the link being edited,
     * a fresh draft when `$id` is null, or (when `$duplicateOf` is set) an
     * unsaved copy of that source link — plus a small bundle of always-needed
     * options. Heavier per-tab data is fetched lazily via dedicated endpoints
     * so this stays light.
     *
     * Null when the requested link (or duplication source) doesn't exist; the
     * controller turns that into a 404.
     *
     * `meta.uid` is handed over because deletes key on UID — the Project
     * Config key — not on the numeric id.
     *
     * @return array{
     *   link: array,
     *   options: array,
     *   meta: array,
     * }|null
     */
    public function bootstrap(?int $id, ?int $duplicateOf, bool $readOnly): ?array
    {
        $plugin = Influx::getInstance();

        if ($duplicateOf !== null) {
            $source = $plugin->links->getLinkById($duplicateOf);

            if (! $source) {
                return null;
            }
            $link = $plugin->links->buildDuplicate($source);
            $isNew = true;
        } elseif ($id === null) {
            $link = new Link([
                'elementType' => Entry::class,
                'processing'  => ProcessingAction::defaults(),
            ]);
            $isNew = true;
        } else {
            $link = $plugin->links->getLinkById($id);

            if (! $link) {
                return null;
            }
            $isNew = false;
        }

        return [
            'link'    => $this->serializer->serialize($link),
            'options' => $this->options->bootstrapOptions(),
            'meta'    => [
                'isNew'          => $isNew,
                'readOnly'       => $readOnly,
                'handle'         => $link->handle ?: null,
                'uid'            => $link->uid ?: null,
                'csrfTokenName'  => Craft::$app->getRequest()->csrfParam,
                'csrfToken'      => Craft::$app->getRequest()->getCsrfToken(),
                'envSuggestions' => $this->options->envAndAliasSuggestions(),
            ],
        ];
    }

    /**
     * Persist a link from the SPA payload. Returns the saved link's
     * serialized state on success, or the unified failure envelope
     * (`{success: false, message, errors}`) on validation failure — never throws
     * for validation; the controller turns the envelope into a 400.
     *
     * The processing policy is healed here — dropping what the target doesn't
     * support, then swapping the missing-element policies to the endpoint shape —
     * rather than left to {@see LinksService::saveLink()}, which repeats both
     * idempotently, so the response can report what changed.
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

        $droppedMatch = $link->pruneMatchForTarget();
        $dropped = $link->pruneProcessingForTarget();
        $migrations = $link->migrateProcessingForEndpointShape();

        if (! $plugin->links->saveLink($link)) {
            return [
                'success' => false,
                'message' => Craft::t('influx', 'Couldn’t save link.'),
                'errors'  => $link->getErrors(),
            ];
        }

        $result = ['success' => true, 'link' => $this->serializer->serialize($link)];

        $notices = [];

        if ($droppedMatch !== null) {
            $notices[] = $this->matchDropNotice($link, $droppedMatch);
        }

        if ($dropped) {
            $notices[] = $this->processingDropNotice($dropped);
        }

        if ($migrations) {
            $notices[] = $this->processingMigrationNotice($migrations);
        }

        if ($notices) {
            $result['notice'] = implode(' ', $notices);
        }

        $warning = $this->overlapWarning($link);

        if ($warning) {
            $result['warning'] = $warning;
        }

        return $result;
    }

    /**
     * Soft warning when the just-saved link's resource mapping overlaps another
     * link's. Two links that both define an `itemEndpoint` for the same
     * structural scope ({@see Link::overlaps()}) would each offer a "Sync from
     * remote" action for the same elements — usually a config mistake worth
     * flagging. Only meaningful once the saved link itself has a resource
     * mapping; returns null (no warning) otherwise. Saving still succeeds — the
     * builder surfaces this alongside the success, it doesn't block it.
     *
     * {@see LinksService::getAllLinks()} includes the just-saved link, so it's
     * skipped by UID.
     */
    protected function overlapWarning(Link $link): ?string
    {
        if (! $link->itemEndpoint) {
            return null;
        }

        $others = [];

        foreach (Influx::getInstance()->links->getAllLinks() as $other) {
            if ($other->uid === $link->uid) {
                continue;
            }

            if (! $other->itemEndpoint) {
                continue;
            }

            if ($link->overlaps($other)) {
                $others[] = $other->name;
            }
        }

        if ($others === []) {
            return null;
        }

        return Craft::t('influx', '{links} also define a resource mapping for this element.', [
            'links' => implode(', ', $others),
        ]);
    }

    /**
     * Human-readable summary of a match key dropped because the link's element
     * type identifies its element from criteria instead
     * ({@see Link::pruneMatchForTarget()}) — config carried over from another
     * element type, or a section switched to a Single. Names the element type,
     * since that's the reason, and points at the criteria that took over.
     */
    protected function matchDropNotice(Link $link, string $attribute): string
    {
        return Craft::t('influx', 'Dropped the “{attribute}” match key: {elementType} links write the element their criteria name.', [
            'attribute'   => $attribute,
            'elementType' => Influx::getInstance()->targets->friendlyNameFor($link->elementType),
        ]);
    }

    /**
     * Human-readable summary of a processing-policy migration
     * ({@see Link::migrateProcessingForEndpointShape()}), shown to the user as
     * a native CP notice after a save that swapped a global delete/disable for
     * its -for-site counterpart (or back). The direction is uniform within one
     * save — the endpoint shape is a single fact — so the reason clause is
     * read off the first migration.
     *
     * @param list<array{from: string, to: string}> $migrations
     */
    protected function processingMigrationNotice(array $migrations): string
    {
        $changes = [];

        foreach ($migrations as $migration) {
            $changes[] = Craft::t('influx', '“{from}” → “{to}”', [
                'from' => $this->processingActionLabel($migration['from']),
                'to'   => $this->processingActionLabel($migration['to']),
            ]);
        }

        $reason = ProcessingAction::tryFrom($migrations[0]['to'])?->isForSite()
            ? Craft::t('influx', 'to match this link’s site-specific endpoints')
            : Craft::t('influx', 'because this link no longer uses site-specific endpoints');

        return Craft::t('influx', 'Adjusted the missing-element policy {reason}: {changes}.', [
            'reason'  => $reason,
            'changes' => implode(', ', $changes),
        ]);
    }

    /**
     * Human-readable summary of the policies dropped because the link's element
     * type doesn't support them ({@see Link::pruneProcessingForTarget()}) — config
     * carried over from another element type, or the `create` default a new link
     * starts on.
     *
     * Grouped by the reason the prune supplied, since one save can drop for more
     * than one (a Global Set link can neither create nor sweep) and a policy
     * listed under the wrong reason would read as a different bug. Group order
     * follows first appearance in `$dropped`, which is configured order.
     *
     * @param list<array{action: string, reason: string}> $dropped
     */
    protected function processingDropNotice(array $dropped): string
    {
        $byReason = [];

        foreach ($dropped as $drop) {
            $byReason[$drop['reason']][] = '“' . $this->processingActionLabel($drop['action']) . '”';
        }

        $sentences = [];

        foreach ($byReason as $reason => $labels) {
            $sentences[] = Craft::t('influx', 'Dropped {policies}: {reason}', [
                'policies' => implode(', ', $labels),
                'reason'   => $reason,
            ]);
        }

        return implode(' ', $sentences);
    }

    /**
     * Label for a stored processing value, falling back to the raw value for one
     * the enum doesn't know (hand-edited config).
     */
    protected function processingActionLabel(string $value): string
    {
        return ProcessingAction::tryFrom($value)?->label() ?? $value;
    }

    /**
     * Mappable fields for a given element type / criteria combination,
     * grouped the same way the Mapping tab renders them. Drives the
     * reactive update when the user changes the section or entry-type
     * dropdowns in the SPA.
     *
     * The wire boundary for {@see MappableField}: the target reports typed
     * descriptors, and they're serialized here (flat and per group). Whatever the
     * target doesn't report — a custom field removed from the layout, a native
     * the entry type hides — simply isn't in the tree.
     *
     * `matchOptions` comes back in the order the SPA's SearchableSelect expects
     * it: clear sentinel first, then the matchable natives, then custom fields.
     *
     * `requiresMatch` rides HERE rather than with the per-element-type capability
     * flags in {@see \GlueAgency\Influx\web\LinkBuilderOptionsPresenter::elementTypeOptions()},
     * because unlike `creating` / `sweeping` / `multiSite` it isn't a fact about the
     * element type: an Entry link needs no match when its section is a Single, which
     * is only knowable from the criteria. This response is already the one the
     * Mapping tab refetches whenever they change, so the flag arrives exactly when
     * the answer can change.
     *
     * @return array{
     *   fields: list<array>,
     *   groups: list<array>,
     *   matchOptions: list<array{label: ?string, kind: ?string, options: list<array{value: string, label: string}>}>,
     *   requiresMatch: bool,
     * }
     */
    public function mappableFields(string $elementType, array $criteria): array
    {
        $stub = new Link(['elementType' => $elementType, 'elementCriteria' => $criteria]);
        $target = Influx::getInstance()->targets->forLink($stub);

        $fields = $target ? $target->getMappableFields($stub) : [];
        $groups = $this->options->groupMappableFields($fields);

        $nativeOptions = $target ? $target->matchableNativeAttributes($stub) : [];
        $fieldOptions = [];

        foreach ($fields as $field) {
            if ($field->native) {
                continue;
            }
            $fieldOptions[] = [
                'value' => $field->handle,
                'label' => "{$field->name} ({$field->handle})",
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

        return [
            'fields'        => MappableField::toArrays($fields),
            'groups'        => $groups,
            'matchOptions'  => $matchOptions,
            'requiresMatch' => $stub->requiresMatch(),
        ];
    }

    /**
     * Fetch the configured endpoint and report rootNode / paginatorNode
     * candidates + sample item structure. Drives the Pagination tab's
     * "Fetch sample" button. Operates on a transient Link built from the
     * SPA's current state, so users can fetch a sample mid-edit without
     * having to save first.
     *
     * Returns `{success: true, report: ...}` on success or `{success: false, message: ...}`
     * when the fetch itself fails (no endpoint, network, bad JSON). Never throws
     * — the UI surface needs the message inline. A response Influx can't read a
     * list of items out of still succeeds: the report comes back partial, with a
     * `warning` and the root-node candidates that fix it
     * ({@see \GlueAgency\Influx\data\FeedInspector::report()}).
     *
     * @return array{success: true, report: array}|array{success: false, message: string}
     */
    public function fetchSample(array $payload): array
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
     * The Mapping tab's default-value element picker: resolve the currently
     * selected ids to elements and hand the rendering to
     * {@see Compat::elementSelectInput()}, which owns the version-sensitive CP
     * render and the matching JS settings.
     *
     * `$readOnly` is passed in rather than re-derived, so one request answers
     * from one read of `allowAdminChanges` (the controller's).
     *
     * `$fieldHandle` names the CUSTOM field the default is being picked for, and
     * shapes the picker after that field ({@see elementSelectConfigFor()}). The
     * SPA only sends it for a custom-field row; a native one (the author) sends
     * nothing, which is what keeps a real custom field that happens to be handled
     * `author` from shaping the author picker.
     *
     * @param string $elementType FQCN of the target element type.
     * @param int[]  $ids         Currently-selected element ids.
     * @return array{html: string, jsSettings: array}
     */
    public function renderElementSelect(string $elementType, array $ids, bool $readOnly, ?string $fieldHandle = null): array
    {
        $elements = [];

        foreach ($ids as $id) {
            $element = Craft::$app->getElements()->getElementById((int) $id, $elementType);

            if ($element) {
                $elements[] = $element;
            }
        }

        $field = $fieldHandle ? Craft::$app->getFields()->getFieldByHandle($fieldHandle) : null;

        return Compat::elementSelectInput($elementType, $elements, $readOnly, $this->elementSelectConfigFor($field));
    }

    /**
     * How the default-value picker for a given field should behave: which
     * sources it may choose from, and how many elements it may hold.
     *
     * A default picked for a relation field has to obey that field's own
     * configuration — offering sections the field can't relate, or a second
     * element to a field that holds one, would let the CP save a default the
     * sync then can't apply.
     *
     * Reads the field's public source settings rather than calling
     * `BaseRelationField::getInputSources()`, for two reasons. It has side
     * effects: the Assets override resolves an OWNING element's upload folder
     * and authorizes an upload token on the session on its way to an answer,
     * neither of which a config query should cause (and there's no owning
     * element here to resolve against anyway). And these same two settings are
     * what the SYNC scopes its lookups by — `$sources` in
     * {@see \GlueAgency\Influx\fields\Entries::scopeBySources()} and
     * {@see \GlueAgency\Influx\fields\Assets::allowedVolumeIds()}, `$source` in
     * {@see \GlueAgency\Influx\fields\GroupScopedRelation::scopeBySources()} —
     * so reading them here is what makes a pickable default a resolvable one.
     *
     * Single-source flavours (Categories, Tags) carry their one source in
     * `$source`; the multi-source ones (Entries, Assets, Users) in `$sources`.
     * All three settings are plain public data on Craft 4 and 5 alike.
     *
     * Anything that isn't a relation field — the native author row, a handle that
     * no longer resolves — keeps the historical shape: every source, one element.
     *
     * @return array{sources: string|string[], limit: int|null, single: bool}
     */
    /**
     * Craft's own icon picker, for an Icon field's default-value cell — the
     * counterpart of {@see renderElementSelect()}, and mounted the same way: the
     * SPA drops the html in and constructs `Craft.IconPicker` off `jsSettings`.
     *
     * Rendered here rather than shipped as data because Craft's icon set runs to
     * thousands of entries with their own search terms, which Craft already
     * searches server-side from inside this control. Whether Pro icons are
     * selectable is the FIELD's setting, derived here for the same reason an
     * element picker's sources are — the SPA has no business knowing about it.
     *
     * The `{% js %}` the picker's template emits goes to the View's buffer rather
     * than into this html, which is exactly why the settings come back separately
     * for the client to construct with.
     *
     * @return array{html: string, jsSettings: array{id: string, freeOnly: bool}}
     */
    public function renderIconPicker(?string $fieldHandle, ?string $value, bool $readOnly): array
    {
        $field = $fieldHandle !== null ? Craft::$app->getFields()->getFieldByHandle($fieldHandle) : null;
        $freeOnly = ! ($field instanceof CraftIconField) || ! $field->includeProIcons;
        $id = 'influx-icon-' . ($fieldHandle ?? 'default');

        if (! method_exists(Cp::class, 'iconPickerHtml')) {
            return ['html' => '', 'jsSettings' => ['id' => $id, 'freeOnly' => $freeOnly]];
        }

        return [
            'html' => Cp::iconPickerHtml([
                'id' => $id,
                // The picker writes the picked name into this input, so it has to
                // exist even though the SPA reads the value off the change event
                // and nothing here is ever POSTed as a form.
                'name'     => $id,
                'value'    => $value !== '' ? $value : null,
                'static'   => $readOnly,
                'freeOnly' => $freeOnly,
            ]),
            'jsSettings' => ['id' => $id, 'freeOnly' => $freeOnly],
        ];
    }

    /**
     * The option list a lazily-declared default select fetches on first use.
     *
     * Resolved from the field's own handle, the same way
     * {@see renderElementSelect()} resolves the field shaping an element picker —
     * so a strategy answers for its own field type and nothing here knows which
     * field types have big lists.
     *
     * A handle that no longer resolves yields no options rather than an error:
     * the row is already showing a mapping that outlived its field, and the
     * builder says so through its missing-mapping badge.
     *
     * The field's own values ONLY. The "nothing picked" row is a sentinel declared
     * on the node ({@see \GlueAgency\Influx\schema\MappingSchemaBuilder::defaultSelect()}),
     * so it is already on screen before this list is ever fetched — returning one
     * here would double it.
     *
     * @return list<array{value: string, label: string}> The SPA's option shape.
     */
    public function defaultOptionsFor(?string $fieldHandle): array
    {
        $field = $fieldHandle !== null ? Craft::$app->getFields()->getFieldByHandle($fieldHandle) : null;

        if ($field === null) {
            return [];
        }

        $options = [];

        foreach (Influx::getInstance()->fields->defaultOptionsFor($field) as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }

    public function elementSelectConfigFor(?CraftFieldInterface $field): array
    {
        if (! $field instanceof BaseRelationField) {
            return ['sources' => '*', 'limit' => 1, 'single' => true];
        }

        return [
            'sources' => $field->source !== null ? [$field->source] : ($field->sources ?? '*'),
            'limit'   => $field->maxRelations,
            'single'  => $field->maxRelations === 1,
        ];
    }
}
