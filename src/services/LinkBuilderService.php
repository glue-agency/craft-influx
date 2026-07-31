<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
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
     * The processing policy is healed to the endpoint shape here — rather than
     * left to {@see LinksService::saveLink()}, which repeats it idempotently —
     * so the response can report what changed.
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

        $migrations = $link->migrateProcessingForEndpointShape();

        if (! $plugin->links->saveLink($link)) {
            return [
                'success' => false,
                'message' => Craft::t('influx', 'Couldn’t save link.'),
                'errors'  => $link->getErrors(),
            ];
        }

        $result = ['success' => true, 'link' => $this->serializer->serialize($link)];

        if ($migrations) {
            $result['notice'] = $this->processingMigrationNotice($migrations);
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
            'fields'       => MappableField::toArrays($fields),
            'groups'       => $groups,
            'matchOptions' => $matchOptions,
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
     * @param string $elementType FQCN of the target element type.
     * @param int[]  $ids         Currently-selected element ids.
     * @return array{html: string, jsSettings: array}
     */
    public function renderElementSelect(string $elementType, array $ids, bool $readOnly): array
    {
        $elements = [];

        foreach ($ids as $id) {
            $element = Craft::$app->getElements()->getElementById((int) $id, $elementType);

            if ($element) {
                $elements[] = $element;
            }
        }

        return Compat::elementSelectInput($elementType, $elements, $readOnly);
    }
}
