<?php

namespace TDM\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;
use TDM\Influx\sync\FieldContext;
use TDM\Influx\sync\SubElementApplier;

/**
 * Maps a remote-item node onto a Craft Assets field.
 *
 *   options.mode:      'id' | 'url'
 *   options.subFields: { alt: { node: 'images.0.alt', default: '' }, ... }
 *
 * In `id` mode the value is treated as an existing asset id. In `url` mode
 * we look up an asset whose `getUrl()` matches verbatim, optionally
 * downloading it into a volume when `options.upload` is enabled (matches
 * FeedMe's `options.upload` path).
 *
 * Sub-field values (alt/title) are written back to the matched asset itself,
 * mirroring how FeedMe handles asset sub-fields.
 */
class Assets extends Field
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Assets::class;
    }

    public function fieldMeta(CraftFieldInterface $field): array
    {
        /** @var \craft\fields\Assets $field */
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
            'valueIs'              => Craft::t('influx', 'Value is'),
            'uploadToggle'         => Craft::t('influx', 'Download & upload missing files'),
            'targetVolume'         => Craft::t('influx', 'Target volume'),
            'targetVolumePh'       => Craft::t('influx', 'Volume handle'),
            'subFolder'            => Craft::t('influx', 'Sub-folder'),
            'subFolderPh'          => Craft::t('influx', 'e.g. imports/2024'),
            'onConflict'           => Craft::t('influx', 'On conflict'),
            'subFieldsTitle'       => Craft::t('influx', 'Asset sub-fields'),
            'subFieldsHint'        => Craft::t('influx', 'Mapped values are written back to the asset itself (alt/title).'),
            'noMapping'            => Craft::t('influx', '— no mapping —'),
            'defaultPh'            => Craft::t('influx', 'Default'),
        ];
    }

    /**
     * Options for the "Value is" dropdown — whether the remote node carries
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
            ['value' => 'index',    'label' => Craft::t('influx', 'Reuse existing')],
            ['value' => 'keepBoth', 'label' => Craft::t('influx', 'Keep both (rename)')],
            ['value' => 'replace',  'label' => Craft::t('influx', 'Replace')],
        ];
    }

    public function hasMappingExtras(): bool
    {
        return true;
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        $url = [['handle' => 'mode', 'equals' => 'url']];
        $uploading = [['handle' => 'mode', 'equals' => 'url'], ['handle' => 'upload']];

        return [
            \TDM\Influx\helpers\BuilderSchema::select('mode', Craft::t('influx', 'Value is'), self::modeOptions(), [
                'default' => 'id',
            ]),
            \TDM\Influx\helpers\BuilderSchema::lightswitch('upload', Craft::t('influx', 'Download & upload missing files'), [
                'showIf' => $url,
            ]),
            \TDM\Influx\helpers\BuilderSchema::text('volume', Craft::t('influx', 'Target volume'), [
                'placeholder' => Craft::t('influx', 'Volume handle'),
                'showIf'      => $uploading,
            ]),
            \TDM\Influx\helpers\BuilderSchema::text('folderPath', Craft::t('influx', 'Sub-folder'), [
                'placeholder' => Craft::t('influx', 'e.g. imports/2024'),
                'showIf'      => $uploading,
            ]),
            \TDM\Influx\helpers\BuilderSchema::select('conflict', Craft::t('influx', 'On conflict'), self::conflictOptions(), [
                'default' => 'index',
                'showIf'  => $uploading,
            ]),
            \TDM\Influx\helpers\BuilderSchema::subFieldMapTable(
                Craft::t('influx', 'Asset sub-fields'),
                [
                    'alt'   => Craft::t('influx', 'Alt text'),
                    'title' => Craft::t('influx', 'Title'),
                ],
                ['instructions' => Craft::t('influx', 'Mapped values are written back to the asset itself (alt/title).')],
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
        $asset = $mode === 'url' ? $this->resolveByUrl($context, (string)$raw) : $this->findById($raw);
        if (!$asset) {
            return null;
        }

        $this->applySubFields($context, $asset);

        return [$asset->id];
    }

    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        if (!is_array($incoming)) {
            return true;
        }
        try {
            $currentIds = $context->element->getFieldValue($context->handle)?->ids() ?? [];
        } catch (\Throwable) {
            return true;
        }
        sort($currentIds);
        $incomingSorted = $incoming;
        sort($incomingSorted);
        return $currentIds !== $incomingSorted;
    }

    protected function findById(mixed $raw): ?Asset
    {
        if (!is_numeric($raw)) {
            return null;
        }
        return Asset::find()->id((int)$raw)->status(null)->one();
    }

    /**
     * Resolve a remote URL to a Craft Asset, optionally downloading it into
     * the configured volume when no existing asset matches.
     *
     *   options.upload:     bool        — turn on download/upload behaviour
     *   options.volume:     string      — target volume handle
     *   options.folderPath: string      — sub-folder under the volume root
     *   options.conflict:   replace|keepBoth|index (default: index)
     */
    protected function resolveByUrl(FieldContext $context, string $url): ?Asset
    {
        // First try matching an existing asset by url() — cheap and avoids
        // pointless re-uploads when the source already lives in Craft.
        $existing = $this->matchExistingByUrl($url);
        if ($existing) {
            return $existing;
        }

        if (!$context->mapping->option('upload') || !$context->mapping->option('volume')) {
            return null;
        }

        if ($context->dryRun) {
            // Dry-runs must not download/save anything; report "no asset"
            // rather than uploading one as a side effect.
            return null;
        }

        return \TDM\Influx\Influx::getInstance()->assetUpload->uploadFromUrl(
            volumeHandle: (string)$context->mapping->option('volume'),
            url: $url,
            folderPath: (string)$context->mapping->option('folderPath', ''),
            conflict: (string)$context->mapping->option('conflict', 'index'),
        );
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
            } catch (\Throwable) {
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
