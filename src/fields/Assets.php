<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;
use craft\fields\Assets as CraftAssetsField;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\SubElementApplier;
use Throwable;

/**
 * Maps a remote-item node onto a Craft Assets field.
 *
 *   options.mode:      'id' | 'url'
 *   options.subFields: { alt: { node: 'images.0.alt', default: '' }, ... }
 *
 * In `id` mode the value is treated as an existing asset id. In `url` mode
 * we look up an asset whose `getUrl()` matches verbatim, optionally
 * downloading it when `options.upload` is enabled (matches FeedMe's
 * `options.upload` path). Uploads land in the field's own configured
 * upload location — never a separate mapping option.
 *
 * Sub-field values (alt/title) are written back to the matched asset itself,
 * mirroring how FeedMe handles asset sub-fields.
 */
class Assets extends Field
{
    public static function craftFieldClass(): ?string
    {
        return CraftAssetsField::class;
    }

    public function fieldMeta(CraftFieldInterface $field): array
    {
        /** @var CraftAssetsField $field */
        return [
            'kind'         => 'asset',
            'allowedKinds' => $field->allowedKinds ?? null,
            'subFields'    => [
                // Each entry: handle => label. The handle is used as the
                // sub-mapping key on the saved Link config.
                'alt'   => Craft::t('influx', 'Alt text'),
                'title' => Craft::t('influx', 'Title'),
            ],
            'modeOptions'     => self::modeOptions(),
            'conflictOptions' => self::conflictOptions(),
            'labels'          => self::extrasLabels() + self::commonExtrasLabels(),
        ];
    }

    /**
     * UI strings rendered inside the asset extras block.
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'valueIs'        => Craft::t('influx', 'Match by'),
            'uploadToggle'   => Craft::t('influx', 'Download & upload missing files'),
            'onConflict'     => Craft::t('influx', 'On conflict'),
            'subFieldsTitle' => Craft::t('influx', 'Asset sub-fields'),
            'subFieldsHint'  => Craft::t('influx', 'Mapped values are written back to the asset itself (alt/title).'),
            'noMapping'      => Craft::t('influx', '— no mapping —'),
            'defaultPh'      => Craft::t('influx', 'Default'),
        ];
    }

    /**
     * Options for the "Match by" dropdown — whether the remote node carries
     * an asset id (default) or a URL we look up / optionally download.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function modeOptions(): array
    {
        // Fixed set — each value drives a parse-time branch in
        // {@see parse()}, so adding a new mode without code support
        // would be silently inert. Not exposed as an event registry.
        return [
            ['value' => 'id',  'label' => Craft::t('influx', 'Asset ID')],
            ['value' => 'url', 'label' => Craft::t('influx', 'URL (lookup or download)')],
        ];
    }

    /**
     * Options for the "On conflict" dropdown when downloading-and-uploading
     * an asset whose filename already exists in the target folder.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function conflictOptions(): array
    {
        // Same story as {@see modeOptions()} — each value maps to a fixed
        // branch in the upload helper, so the list is intentionally closed.
        return [
            ['value' => 'index',   'label' => Craft::t('influx', 'Reuse existing')],
            ['value' => 'replace', 'label' => Craft::t('influx', 'Replace')],
        ];
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        $url = [['handle' => 'mode', 'equals' => 'url']];
        $uploading = [['handle' => 'mode', 'equals' => 'url'], ['handle' => 'upload']];

        return [
            BuilderSchema::select('mode', Craft::t('influx', 'Match by'), self::modeOptions(), [
                'default' => 'id',
            ]),
            BuilderSchema::lightswitch('upload', Craft::t('influx', 'Download & upload missing files'), [
                'showIf' => $url,
            ]),
            BuilderSchema::select('conflict', Craft::t('influx', 'On conflict'), self::conflictOptions(), [
                'default' => 'index',
                'showIf'  => $uploading,
            ]),
            BuilderSchema::elementSubFields(
                Craft::t('influx', 'Asset sub-fields'),
                [
                    BuilderSchema::text('alt', Craft::t('influx', 'Alt text')),
                    BuilderSchema::text('title', Craft::t('influx', 'Title')),
                ],
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null || $raw === '') {
            return null;
        }

        $mode = $context->mapping->option('mode', 'id');
        $asset = $mode === 'url' ? $this->resolveByUrl($context, (string) $raw) : $this->findById($raw);

        if (! $asset) {
            return null;
        }

        $this->applySubFields($context, $asset);

        return [$asset->id];
    }

    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        if (! is_array($incoming)) {
            return true;
        }

        try {
            $currentIds = $context->element->getFieldValue($context->handle)?->ids() ?? [];
        } catch (Throwable) {
            return true;
        }
        sort($currentIds);
        $incomingSorted = $incoming;
        sort($incomingSorted);

        return $currentIds !== $incomingSorted;
    }

    protected function findById(mixed $raw): ?Asset
    {
        if (! is_numeric($raw)) {
            return null;
        }

        return Asset::find()->id((int) $raw)->status(null)->one();
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
        $existing = $this->matchExistingByUrl($url);

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
     * @return array{0: ?\craft\models\Volume, 1: string} Volume (null when
     * the field has no resolvable volume source) and rendered subpath.
     */
    protected function uploadLocation(FieldContext $context): array
    {
        $field = $context->craftField;

        if (! $field instanceof CraftAssetsField) {
            return [null, ''];
        }

        $source = $field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource;
        $subpath = $field->restrictLocation ? $field->restrictedLocationSubpath : $field->defaultUploadLocationSubpath;

        $volume = null;

        if (is_string($source) && str_starts_with($source, 'volume:')) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid(substr($source, 7));
        }

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

    protected function matchExistingByUrl(string $url): ?Asset
    {
        // Match by filename first — much faster than enumerating volumes.
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($name === '' || $name === false) {
            return null;
        }
        $asset = Asset::find()->filename($name)->status(null)->one();

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
     * Apply sub-mappings (alt/title) to the matched asset and persist it when
     * something changed. Skipped under dry-run — the asset is a real, saved
     * element the debug inspector must not mutate.
     */
    protected function applySubFields(FieldContext $context, Asset $asset): void
    {
        if ($context->dryRun) {
            return;
        }

        if ((new SubElementApplier())->apply($asset, $context)) {
            Craft::$app->getElements()->saveElement($asset, false);
        }
    }
}
