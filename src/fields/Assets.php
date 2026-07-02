<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;
use craft\fields\Assets as CraftAssetsField;
use craft\models\Volume;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\Influx;
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
    public static function craftFieldClass(): ?string
    {
        return CraftAssetsField::class;
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        $url = [['handle' => 'mode', 'equals' => 'url']];
        $uploading = [['handle' => 'mode', 'equals' => 'url'], ['handle' => 'upload']];

        // The mode / conflict option sets are fixed: each value maps to a
        // branch in parse() / the upload helper, so they're intentionally
        // closed (no event registry — an unknown value would be inert).
        return [
            // Grouped options so this renders via the shared SearchableSelect,
            // matching the relation / author "Match by" controls. id + url are
            // the asset's native identifiers; the handle stays `mode` so saved
            // configs keep round-tripping.
            BuilderSchema::select('mode', Craft::t('influx', 'Match by'),
                [
                    [
                        'label'   => Craft::t('influx', 'Asset'),
                        'kind'    => 'element',
                        'options' => [
                            ['value' => 'id',  'label' => Craft::t('influx', 'ID (id)')],
                            ['value' => 'url', 'label' => Craft::t('influx', 'URL (url)')],
                        ],
                    ],
                ],
                [
                    'default' => 'id',
                ]
            ),
            BuilderSchema::lightswitch('upload', Craft::t('influx', 'Download & upload missing files'), [
                'showIf' => $url,
            ]),
            BuilderSchema::select('conflict', Craft::t('influx', 'On conflict'), [
                ['value' => 'index',   'label' => Craft::t('influx', 'Use existing')],
                ['value' => 'replace', 'label' => Craft::t('influx', 'Replace')],
            ], [
                'default' => 'index',
                'showIf'  => $uploading,
            ]),
            BuilderSchema::elementSubFields(
                Craft::t('influx', 'Sub-fields'),
                [
                    BuilderSchema::text('alt', Craft::t('influx', 'Alt text')),
                    BuilderSchema::text('title', Craft::t('influx', 'Title')),
                ],
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        // resolve() already normalises empty to null.
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $mode = $context->mapping->option('mode', 'id');

        // A single source node can carry many values (a JSON array of URLs or
        // ids) — resolve each to an asset, exactly as a relation field maps a
        // list of remote references to a list of element ids.
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
     * no existing asset matches. The destination isn't a mapping option —
     * the Assets field already declares where its files live, so the upload
     * goes to the field's own configured location (see uploadLocation()).
     *
     *   options.upload:   bool — turn on download/upload behaviour
     *   options.conflict: replace|index (default: index)
     */
    protected function resolveByUrl(FieldContext $context, string $url): ?Asset
    {
        // First try matching an existing asset by url() — cheap and avoids
        // pointless re-uploads when the source already lives in Craft.
        $existing = $this->matchExistingByUrl($context, $url);

        if ($existing) {
            return $existing;
        }

        if (! $context->mapping->option('upload')) {
            return null;
        }

        if ($context->dryRun) {
            // Dry-runs must not download/save anything; report "no asset"
            // rather than uploading one as a side effect.
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
                // Subpaths may be object templates ({slug}, …) rendered
                // against the element being synced — same as a CP upload.
                $subpath = Craft::$app->getView()->renderObjectTemplate($subpath, $context->element);
            } catch (Throwable) {
                // A failing dynamic subpath shouldn't kill the sync run —
                // fall back to the volume root.
                $subpath = '';
            }
        }

        return [$volume, trim($subpath, '/')];
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

    protected function matchExistingByUrl(FieldContext $context, string $url): ?Asset
    {
        // Match by filename first — much faster than enumerating volumes.
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($name === '' || $name === false) {
            return null;
        }

        $query = Asset::find()->filename($name)->status(null);
        $this->scopeToAllowedVolumes($query, $context);
        $asset = $query->one();

        if ($asset) {
            try {
                if ($asset->getUrl() === $url) {
                    return $asset;
                }
            } catch (Throwable) {
                // Volume might not expose URLs — fall through.
            }
        }

        return $asset; // best-effort: same filename, possibly different host
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
            // '*' (any volume) or null — no constraint.
            return null;
        }

        $ids = [];

        foreach ($sources as $source) {
            $volume = $this->volumeFromSource($source);

            if ($volume) {
                $ids[] = $volume->id;
            }
        }

        return $ids ?: null;
    }
}
