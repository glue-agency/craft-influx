<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use GlueAgency\Influx\exceptions\AssetUploadException;
use GlueAgency\Influx\Influx;
use GuzzleHttp\Client;
use Throwable;

/**
 * Downloads a remote URL and saves it into a Craft volume as an Asset.
 * Extracted from {@see \GlueAgency\Influx\fields\Assets} so the field strategy
 * stays focused on shaping the mapping, not on HTTP / disk I/O.
 *
 * Lookup by URL falls back to upload only when explicitly enabled — that's
 * the same boundary FeedMe draws via `options.upload`.
 *
 * Two entry points, because there are two kinds of caller. A field strategy wants
 * a saved Asset it can relate to ({@see uploadFromUrl()}); an element target owns
 * the element the engine is about to save and only wants the FILE
 * ({@see downloadToTemp()} + {@see filenameFor()}), so it can hand it to that
 * element instead of committing a second one behind the engine's back. Both share
 * this class's HTTP client, its redirect policy and its scheme guard.
 */
class AssetUploadService extends Component
{
    protected Client $client;

    public function init(): void
    {
        parent::init();

        $followRedirects = Influx::getInstance()->getSettings()->followRedirects;

        $this->client = Craft::createGuzzleClient([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'allow_redirects' => $followRedirects
                ? ['max' => 5, 'strict' => true, 'protocols' => ['http', 'https']]
                : false,
        ]);
    }

    /**
     * Download $url and create (or reuse) an Asset in the target volume.
     *
     * @param string $volumeHandle The Craft volume to upload into.
     * @param string $url          Fully-qualified URL to download.
     * @param string $folderPath   Optional sub-folder path (no leading slash).
     * @param string $conflict     'replace' | 'index' (default — reuse a
     *                             matching filename already in the folder).
     *
     * @throws AssetUploadException with the actual cause — misconfigured
     * volume, failed download, or element validation errors. Callers must
     * not see "no asset" and "upload broke" as the same outcome.
     */
    public function uploadFromUrl(
        string $volumeHandle,
        string $url,
        string $folderPath = '',
        string $conflict = 'index',
    ): Asset {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if (! $volume) {
            throw new AssetUploadException("Volume '{$volumeHandle}' does not exist.");
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            trim($folderPath, '/'),
            $volume,
        );

        $filename = $this->filenameFor($url);

        if ($conflict === 'index') {
            $existing = Asset::find()
                ->folderId($folder->id)
                ->filename($filename)
                ->status(null)
                ->one();

            if ($existing) {
                return $existing;
            }
        }

        $tempPath = $this->downloadToTemp($url);

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = ($conflict !== 'replace');
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (! Craft::$app->getElements()->saveElement($asset, true)) {
                throw new AssetUploadException(
                    "Saving asset '{$filename}' failed: " . implode('; ', $asset->getFirstErrors()),
                );
            }

            return $asset;
        } catch (AssetUploadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AssetUploadException("Uploading '{$url}' failed: " . $e->getMessage(), previous: $e);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * The safe filename a remote URL lands under, falling back to a hash of the
     * URL when the path carries no basename at all.
     *
     * Public because the callers that download a file themselves
     * ({@see \GlueAgency\Influx\targets\AssetTarget}, {@see \GlueAgency\Influx\targets\UserTarget})
     * need the same name this service would have chosen — a second spelling of it
     * is how the same URL ends up stored twice.
     */
    public function filenameFor(string $url): string
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');

        if ($name === '' || $name === false) {
            $name = 'asset-' . substr(md5($url), 0, 8);
        }

        return AssetsHelper::prepareAssetName($name);
    }

    /**
     * The URL is feed-controlled, so only http(s) schemes are accepted —
     * anything else (`file://`, …) would turn a feed value into a local-file
     * read / SSRF vector.
     *
     * Public because the element targets that own the saved element can't go
     * through {@see uploadFromUrl()}: that one saves the asset itself, while a
     * target hands the temp file to the element and lets the engine's own
     * {@see \GlueAgency\Influx\targets\ElementTargetInterface::save()} commit it.
     * They get the file, this keeps owning HOW it's fetched — including the scheme
     * guard, which is the whole reason a feed URL is safe to follow.
     *
     * The caller owns the returned path and must unlink it; Craft moves it into the
     * volume on save, so an asset save that lands leaves nothing behind.
     *
     * @throws AssetUploadException when the download fails or the server
     * answers with a non-2xx status.
     */
    public function downloadToTemp(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AssetUploadException("Refusing to download '{$url}': only http and https URLs are allowed.");
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . '/influx-' . uniqid('', true);
        FileHelper::createDirectory(dirname($tempPath));

        try {
            $response = $this->client->get($url, [
                'sink'        => $tempPath,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            throw new AssetUploadException("Downloading '{$url}' failed: " . $e->getMessage(), previous: $e);
        }

        if ($response->getStatusCode() >= 300 || ! is_file($tempPath)) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }

            throw new AssetUploadException("Downloading '{$url}' failed with HTTP {$response->getStatusCode()}.");
        }

        return $tempPath;
    }
}
