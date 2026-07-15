<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;
use craft\fields\Assets as CraftAssetsField;
use craft\models\Volume;
use GlueAgency\Influx\helpers\SchemaBuilder;
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

    public function schema(CraftFieldInterface $field): array
    {
        $url = [['handle' => 'mode', 'equals' => 'url']];
        $uploading = [['handle' => 'mode', 'equals' => 'url'], ['handle' => 'upload']];

        // mode / conflict values each map to a fixed parse() branch — intentionally
        // closed, no event registry
        return SchemaBuilder::make()
            // Grouped so it renders via the shared SearchableSelect like the
            // relation "Match by"; handle stays `mode` so saved configs round-trip
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
            ->elementSubFields([
                'label'     => Craft::t('influx', 'Sub-fields'),
                'subFields' => SchemaBuilder::make()
                    ->text(['handle' => 'alt', 'label' => Craft::t('influx', 'Alt text')])
                    ->text(['handle' => 'title', 'label' => Craft::t('influx', 'Title')])
                    ->toArray(),
            ])
            ->toArray();
    }

    public function parse(FieldContext $context): mixed
    {
        // resolve() already normalises empty to null.
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        $mode = $context->mapping->option('mode', 'id');

        // A source node may carry many values (array of URLs/ids); resolve each
        // to an asset, like a relation field maps a list of references
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
        // Try matching an existing asset by url() first — avoids needless re-uploads
        $existing = $this->matchExistingByUrl($context, $url);

        if ($existing) {
            return $existing;
        }

        if (! $context->mapping->option('upload')) {
            return null;
        }

        if ($context->dryRun) {
            // Dry-runs must not download/save — report "no asset" instead
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
                // Subpaths may be object templates, rendered against the synced element
                $subpath = Craft::$app->getView()->renderObjectTemplate($subpath, $context->element);
            } catch (Throwable) {
                // A failing subpath shouldn't kill the sync — fall back to the volume root
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
