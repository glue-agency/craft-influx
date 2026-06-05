<?php

namespace TDM\Influx\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Asset;

/**
 * Maps a remote-item node onto a Craft Assets field.
 *
 *   options.mode:      'id' | 'url'
 *   options.subFields: { alt: { node: 'images.0.alt', default: '' }, ... }
 *
 * In `id` mode the value is treated as an existing asset id. In `url` mode
 * we look up an asset whose `getUrl()` matches verbatim — true download /
 * upload-by-URL is a follow-up (matches FeedMe's `options.upload` path).
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
        // {@see parseField()}, so adding a new mode without code support
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

    public function parseField(): mixed
    {
        $raw = $this->fetchSimpleValue();
        if ($raw === null || $raw === '') {
            return null;
        }

        $mode = $this->fieldInfo['options']['mode'] ?? 'id';
        $asset = $mode === 'url' ? $this->resolveByUrl((string)$raw) : $this->findById($raw);
        if (!$asset) {
            return null;
        }

        $this->applySubFields($asset);

        return [$asset->id];
    }

    public function hasChanged(ElementInterface $element, mixed $incoming): bool
    {
        if (!is_array($incoming)) {
            return true;
        }
        try {
            $currentIds = $element->getFieldValue($this->fieldHandle)?->ids() ?? [];
        } catch (\Throwable) {
            return true;
        }
        sort($currentIds);
        $incomingSorted = $incoming;
        sort($incomingSorted);
        return $currentIds !== $incomingSorted;
    }

    private function findById(mixed $raw): ?Asset
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
    private function resolveByUrl(string $url): ?Asset
    {
        // First try matching an existing asset by url() — cheap and avoids
        // pointless re-uploads when the source already lives in Craft.
        $existing = $this->matchExistingByUrl($url);
        if ($existing) {
            return $existing;
        }

        $opts = $this->fieldInfo['options'] ?? [];
        if (empty($opts['upload']) || empty($opts['volume'])) {
            return null;
        }

        return \TDM\Influx\Influx::getInstance()->assetUpload->uploadFromUrl(
            volumeHandle: (string)$opts['volume'],
            url: $url,
            folderPath: (string)($opts['folderPath'] ?? ''),
            conflict: (string)($opts['conflict'] ?? 'index'),
        );
    }

    private function matchExistingByUrl(string $url): ?Asset
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

    private function applySubFields(Asset $asset): void
    {
        (new SubElementPopulator())->populate($asset, $this->item, $this->fieldInfo, $this->link);
    }
}
