<?php

namespace TDM\Influx\fields;

use Cake\Utility\Hash;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;

/**
 * Maps a feed node onto a Craft Assets field.
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

    /**
     * Delegate sub-field application to the shared Relation logic — Assets
     * is conceptually a relation that hangs off the parent element, and the
     * `nativeFields[]` / `fields[]` shape is identical to Entries/Users/etc.
     *
     * Borrowing the implementation that way keeps the recursive sub-element
     * walker in one place (see {@see Relation::populateSubElement}).
     */
    private function applySubFields(Asset $asset): void
    {
        $custom = $this->fieldInfo['fields'] ?? [];
        $native = $this->fieldInfo['nativeFields'] ?? [];
        if (empty($custom) && empty($native)) {
            return;
        }

        $touched = false;
        foreach ($native as $handle => $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $value = $this->resolveSub($sub);
            if ($value === null) {
                continue;
            }
            if ($asset->hasAttribute($handle) || property_exists($asset, $handle)) {
                $asset->{$handle} = $value;
                $touched = true;
            }
        }

        $fieldsRegistry = \TDM\Influx\Influx::getInstance()->fields;
        foreach ($custom as $handle => $sub) {
            $craftField = $asset->getFieldLayout()?->getFieldByHandle($handle);
            if (!$craftField || !is_array($sub)) {
                continue;
            }
            $strategy = $fieldsRegistry->forCraftField($craftField);
            $strategy->setContext($craftField, $handle, $sub, $this->feedData, $this->link, $asset);
            $value = $strategy->parseField();
            if ($value === null) {
                continue;
            }
            $strategy->apply($asset, $value);
            $touched = true;
        }

        if ($touched) {
            Craft::$app->getElements()->saveElement($asset, false);
        }
    }

    private function resolveSub(array $sub): mixed
    {
        $node = $sub['node'] ?? null;
        $value = $node ? Hash::get($this->feedData, $node) : null;
        if ($value === null || $value === '') {
            $value = $sub['default'] ?? null;
        }
        return ($value === null || $value === '') ? null : $value;
    }
}
