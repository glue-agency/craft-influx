<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;
use craft\fields\Assets as CraftAssetsField;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use craft\models\Volume;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use Throwable;

/**
 * Maps a remote-item node onto a Craft Assets field.
 *
 *   options.mode:      'id' | 'url'
 *   options.subFields: { alt: { node: 'images.0.alt', default: '' }, ... }
 *
 * In `id` mode the value is treated as an existing asset id. In `url` mode we
 * look up an asset by filename, preferring one whose `getUrl()` matches the
 * remote URL exactly but falling back (best-effort) to a same-filename asset
 * when no URL matches — so a CDN/host change doesn't force a re-download. When
 * nothing matches and `options.upload` is enabled the file is downloaded
 * (matches FeedMe's `options.upload` path). Uploads land in the field's own
 * configured upload location — never a separate mapping option.
 *
 * Sub-field values (alt/title) are written back to the matched asset itself,
 * mirroring how FeedMe handles asset sub-fields.
 */
class Assets extends RelationalField
{
    /**
     * How many same-filename assets a URL match inspects before settling for the
     * first one. Bounded because a filename can be reused across every folder of
     * every volume the field relates: enough candidates to find the exact-URL hit
     * in any realistic library, few enough that one pathological filename can't
     * turn a single URL into an unbounded result set (each candidate also costs a
     * `getUrl()`).
     */
    protected const URL_MATCH_CANDIDATES = 20;

    public static function craftFieldClass(): ?string
    {
        return CraftAssetsField::class;
    }

    public function childrenKind(): ?string
    {
        return 'assets';
    }

    /**
     * The `mode` and `conflict` values each map to a fixed {@see parse()}
     * branch, so both lists are intentionally closed — deliberately NOT routed
     * through {@see \GlueAgency\Influx\events\RegisterMappingOptionsEvent}.
     * `mode` is grouped so it renders via the shared SearchableSelect like the
     * relation "Match by"; its handle stays `mode` so saved configs round-trip.
     *
     * The matched asset's sub-fields are ONE card over two write channels: the
     * asset's own attributes (alt / title) ride `nativeFields`, the custom
     * fields of the volumes this field can relate ride `fields` and are marked
     * with the row-level `channel` key {@see SchemaBuilder::elementSubFields()}
     * documents. Two cards were an implementation detail of that split showing
     * through — to an editor filling them in they are one list of the asset's
     * fields.
     *
     * Alt and Title are offered only where a volume's layout includes them.
     * Craft treats both as optional native layout elements, so a volume can drop
     * either, and a row nobody can fill in the asset's own editor has no
     * business here — the same rule {@see Relation::nativeSubFields()} follows
     * for a related entry's title. The attribute stays writable whatever the
     * layout says, which is why an ALREADY-SAVED row keeps applying: dropping it
     * would make rendering a mapping destructive, and this schema is rebuilt
     * from live volume layouts on every request.
     *
     * Custom sub-fields are offered as plain text rows for now: the `fields`
     * channel coerces per the target field's own strategy anyway, so the row
     * only needs to supply a source node and an optional default.
     */
    public function schema(CraftFieldInterface $field): SchemaBuilder
    {
        /** @var CraftAssetsField $field */
        $url = [['handle' => 'mode', 'equals' => 'url']];
        $uploading = [['handle' => 'mode', 'equals' => 'url'], ['handle' => 'upload']];
        $subFields = $this->subFieldRows($field);

        return SchemaBuilder::make()
            ->select([
                'handle'  => 'mode',
                'label'   => Craft::t('influx', 'Match by'),
                'options' => [
                    [
                        'label'   => Craft::t('influx', 'Asset'),
                        'kind'    => 'element',
                        'options' => [
                            ['value' => 'id',  'label' => Craft::t('influx', 'ID (id)')],
                            ['value' => 'url', 'label' => Craft::t('influx', 'URL (url)')],
                        ],
                    ],
                ],
                'default' => 'id',
            ])
            ->lightswitch([
                'handle' => 'upload',
                'label'  => Craft::t('influx', 'Download & upload missing files'),
                'showIf' => $url,
            ])
            ->select([
                'handle'  => 'conflict',
                'label'   => Craft::t('influx', 'On conflict'),
                'options' => [
                    ['value' => 'index',   'label' => Craft::t('influx', 'Use existing')],
                    ['value' => 'replace', 'label' => Craft::t('influx', 'Replace')],
                ],
                'default' => 'index',
                'showIf'  => $uploading,
            ])
            ->when($subFields, fn(SchemaBuilder $builder) => $builder->elementSubFields([
                'label'     => Craft::t('influx', 'Sub-fields'),
                'subFields' => $subFields,
            ]));
    }

    /**
     * The asset attributes a mapping can write, each offered only where a volume
     * layout includes it — `craft\fieldlayoutelements\assets\AltField` and
     * `AssetTitleField` are optional native layout elements in both Craft 4 and
     * 5, so `isFieldIncluded()` is the same probe {@see Relation::nativeSubFields()}
     * uses and needs no version seam.
     *
     * Emitted in a fixed order rather than in layout order, so the rows don't
     * reshuffle depending on which volume happens to contribute which
     * attribute; the label comes from the first layout that includes it.
     *
     * @return list<array>
     */
    protected function nativeSubFields(BaseRelationField $field): array
    {
        $labels = [];

        foreach ($this->sourceFieldLayouts($field) as $layout) {
            if (! $layout instanceof FieldLayout) {
                continue;
            }

            foreach (['alt' => 'Alternative Text', 'title' => 'Title'] as $attribute => $fallback) {
                if (isset($labels[$attribute]) || ! $layout->isFieldIncluded($attribute)) {
                    continue;
                }

                $labels[$attribute] = $layout->getField($attribute)->label() ?: Craft::t('app', $fallback);
            }
        }

        $builder = SchemaBuilder::make();

        foreach (['alt', 'title'] as $attribute) {
            if (isset($labels[$attribute])) {
                $builder->text(['handle' => $attribute, 'label' => $labels[$attribute]]);
            }
        }

        return $builder->toArray();
    }

    /**
     * An asset default is a file the operator picks in the CP, so the row offers
     * an asset selector rather than a box to paste a URL into; {@see parse()}
     * matches a picked default by id.
     */
    public function defaultEditor(CraftFieldInterface $field): ?array
    {
        return [
            'type'        => SchemaBuilder::ELEMENT,
            'elementType' => Asset::class,
        ];
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed. A source node may carry many values (a list of URLs or ids); each
     * is resolved to an asset, the way a relation field maps a list of
     * references.
     *
     * A freshly uploaded asset is reported as a plain touched child, never
     * `created`: {@see resolveByUrl()} hands back a match and an upload the same
     * way, so uploadedness isn't knowable here (unlike {@see Relation::parse()},
     * which creates the element itself).
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        // A picked default is an asset id (see defaultEditor()), so it takes the
        // id branch even under `url` mode — where it would otherwise be
        // basename-matched into nothing.
        $mode = $context->mapping->usesDefault($context->item) ? 'id' : $context->mapping->option('mode', 'id');

        $ids = [];

        foreach ($this->referenceValues($raw) as $value) {
            $asset = $mode === 'url' ? $this->resolveByUrl($context, (string) $value) : $this->findById($context, $value);

            if (! $asset) {
                continue;
            }

            $this->persistSubElement($context, $asset);
            $ids[] = $asset->id;
        }

        return $ids ?: null;
    }

    protected function findById(FieldContext $context, mixed $raw): ?Asset
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $query = Asset::find()->id((int) $raw)->status(null);
        $this->scopeToAllowedVolumes($query, $context);

        return $query->one();
    }

    /**
     * Resolve a remote URL to a Craft Asset, optionally downloading it when
     * no existing asset matches. An existing asset is matched first so a
     * re-sync doesn't needlessly re-upload the same file. The destination isn't
     * a mapping option — the Assets field already declares where its files
     * live, so the upload goes to the field's own configured location (see
     * uploadLocation()). Under dry-run nothing is downloaded or saved, so the
     * URL reports as "no asset".
     *
     *   options.upload:   bool — turn on download/upload behaviour
     *   options.conflict: replace|index (default: index)
     */
    protected function resolveByUrl(FieldContext $context, string $url): ?ElementInterface
    {
        $existing = $this->matchExistingByUrl($context, $url);

        if ($existing) {
            return $existing;
        }

        if (! $context->mapping->option('upload')) {
            return null;
        }

        if ($context->dryRun) {
            return null;
        }

        [$volume, $subpath] = $this->uploadLocation($context);

        if (! $volume) {
            return null;
        }

        return Influx::getInstance()->assetUpload->uploadFromUrl(
            volumeHandle: $volume->handle,
            url: $url,
            folderPath: $subpath,
            conflict: (string) $context->mapping->option('conflict', 'index'),
        );
    }

    /**
     * Upload destination derived from the field's own settings — the
     * restricted location when the field is locked to a single folder, the
     * default upload location otherwise. Mirrors where a CP user's manual
     * upload through this field would land.
     *
     * Subpaths may be object templates, so they're rendered against the synced
     * element; a subpath that fails to render falls back to the volume root
     * rather than killing the sync.
     *
     * @return array{0: ?Volume, 1: string} Volume (null when the field has no
     * resolvable volume source) and rendered subpath.
     */
    protected function uploadLocation(FieldContext $context): array
    {
        $field = $context->craftField;

        if (! $field instanceof CraftAssetsField) {
            return [null, ''];
        }

        $source = $field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource;
        $subpath = $field->restrictLocation ? $field->restrictedLocationSubpath : $field->defaultUploadLocationSubpath;

        $volume = $this->volumeFromSource($source);

        $subpath = (string) ($subpath ?? '');

        if ($subpath !== '') {
            try {
                $subpath = Craft::$app->getView()->renderObjectTemplate($subpath, $context->element);
            } catch (Throwable) {
                $subpath = '';
            }
        }

        return [$volume, trim($subpath, '/')];
    }

    /**
     * Field layouts the mapping's custom sub-fields are offered from: one per
     * volume the field may relate. A volume without a layout contributes
     * nothing rather than a null the caller has to filter.
     *
     * @return iterable<FieldLayout|null>
     */
    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        if (! $field instanceof CraftAssetsField) {
            return;
        }

        foreach ($this->allowedVolumes($field) as $volume) {
            $layout = $volume->getFieldLayout();

            if ($layout) {
                yield $layout;
            }
        }
    }

    /**
     * Volumes the field may relate assets from: every volume when it accepts
     * any source (`sources === '*'`), otherwise the ones its `volume:UID`
     * sources decode to. The schema-side twin of {@see allowedVolumeIds()},
     * which asks the same question of a running sync.
     *
     * @return list<Volume>
     */
    protected function allowedVolumes(CraftAssetsField $field): array
    {
        $sources = $field->sources ?? '*';

        if (! is_array($sources)) {
            return array_values(Craft::$app->getVolumes()->getAllVolumes());
        }

        return $this->volumesFromSources($sources);
    }

    /**
     * Decode a field's source list into the volumes it names, dropping keys
     * that aren't volume sources or whose UID doesn't resolve here.
     *
     * @param array<mixed> $sources
     * @return list<Volume>
     */
    protected function volumesFromSources(array $sources): array
    {
        $volumes = [];

        foreach ($sources as $source) {
            $volume = $this->volumeFromSource($source);

            if ($volume) {
                $volumes[] = $volume;
            }
        }

        return $volumes;
    }

    /**
     * Resolve a `volume:UID` field source key to its Volume in this
     * environment, or null when the key isn't a volume source or the UID
     * doesn't resolve. Both the upload destination and the allowed-volume
     * scoping decode volume sources this way.
     */
    protected function volumeFromSource(mixed $source): ?Volume
    {
        $uid = $this->sourceUid($source, 'volume:');

        if ($uid === null) {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByUid($uid);
    }

    /**
     * Match an existing asset for a remote URL. Filename first — much faster
     * than enumerating volumes — then the candidate whose `getUrl()` matches the
     * remote URL exactly.
     *
     * FALLBACK: when no candidate's URL matches, the first same-filename one is
     * returned as a best-effort match (possibly a different host), so a CDN/host
     * change doesn't force a re-download. That also covers a volume exposing no
     * URLs at all: `getUrl()` throws there, and a throwing candidate is skipped
     * rather than allowed to abandon the remaining ones.
     *
     * Typed on ElementInterface — always an Asset in practice — so the candidate
     * seam can be stubbed without booting Craft, the same trade
     * {@see Relation::findOne()} makes.
     */
    protected function matchExistingByUrl(FieldContext $context, string $url): ?ElementInterface
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($name === '') {
            return null;
        }

        $candidates = $this->candidatesByFilename($context, $name);

        foreach ($candidates as $candidate) {
            try {
                if ($candidate->getUrl() === $url) {
                    return $candidate;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * The same-filename assets a URL match chooses from, scoped to the field's
     * allowed volumes and capped at {@see URL_MATCH_CANDIDATES}. Extracted so
     * tests can supply candidates without booting Craft.
     *
     * The order is the query's own, so the first row is the one the previous
     * single-row lookup returned — the fallback picks exactly what it used to.
     *
     * @return list<ElementInterface>
     */
    protected function candidatesByFilename(FieldContext $context, string $filename): array
    {
        $query = Asset::find()->filename($filename)->status(null)->limit(self::URL_MATCH_CANDIDATES);
        $this->scopeToAllowedVolumes($query, $context);

        return array_values($query->all());
    }

    /**
     * Constrain an asset lookup to the volumes the field is allowed to relate.
     * A relation can only ever point at an asset in one of the field's source
     * volumes, so matching by id/url must honour that boundary too — otherwise
     * a same-filename file in an unrelated volume could be linked, or an id
     * from outside the field's scope accepted. A field set to "all volumes"
     * (`sources === '*'`) imposes no constraint.
     */
    protected function scopeToAllowedVolumes(mixed $query, FieldContext $context): void
    {
        $volumeIds = $this->allowedVolumeIds($context);

        if ($volumeIds !== null) {
            $query->volumeId($volumeIds);
        }
    }

    /**
     * Volume ids the field's sources resolve to, or null when the field
     * relates assets from any volume (no scoping). Returns the resolved ids
     * even if empty is impossible here — an unresolvable source list falls
     * back to null rather than silently matching nothing.
     *
     * @return int[]|null
     */
    protected function allowedVolumeIds(FieldContext $context): ?array
    {
        $field = $context->craftField;

        if (! $field instanceof CraftAssetsField) {
            return null;
        }

        $sources = $field->sources ?? '*';

        if (! is_array($sources)) {
            return null;
        }

        $ids = [];

        foreach ($this->volumesFromSources($sources) as $volume) {
            $ids[] = $volume->id;
        }

        return $ids ?: null;
    }
}
