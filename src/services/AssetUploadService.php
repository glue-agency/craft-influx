<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\errors\AssetException;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use GuzzleHttp\Client;
use TDM\Influx\exceptions\InfluxException;
use Throwable;

/**
 * Downloads a remote URL and saves it into a Craft volume as an Asset.
 * Extracted from {@see \TDM\Influx\fields\Assets} so the field strategy
 * stays focused on shaping the mapping, not on HTTP / disk I/O.
 *
 * Lookup by URL falls back to upload only when explicitly enabled — that's
 * the same boundary FeedMe draws via `options.upload`.
 */
class AssetUploadService extends Component
{
    private Client $client;

    public function init(): void
    {
        parent::init();
        $this->client = Craft::createGuzzleClient([
            'timeout'         => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Download $url and create (or reuse) an Asset in the target volume.
     *
     * @param string $volumeHandle The Craft volume to upload into.
     * @param string $url          Fully-qualified URL to download.
     * @param string $folderPath   Optional sub-folder path (no leading slash).
     * @param string $conflict     'replace' | 'keepBoth' | 'index' (default).
     *
     * @return Asset|null  The resulting asset, or null on failure.
     */
    public function uploadFromUrl(
        string $volumeHandle,
        string $url,
        string $folderPath = '',
        string $conflict = 'index',
    ): ?Asset {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
        if (!$volume) {
            throw new InfluxException("Volume '{$volumeHandle}' does not exist.");
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            trim($folderPath, '/'),
            $volume,
        );

        $filename = $this->filenameFor($url);

        // 'index' mode — return the existing asset if its filename already
        // matches in the target folder. Mirrors FeedMe's SCENARIO_INDEX path.
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
        if ($tempPath === null) {
            return null;
        }

        try {
            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename($filename);
            $asset->newFolderId = $folder->id;
            $asset->setVolumeId($volume->id);
            $asset->avoidFilenameConflicts = ($conflict !== 'replace');
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset, true)) {
                return null;
            }
            return $asset;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function filenameFor(string $url): string
    {
        $name = basename(parse_url($url, PHP_URL_PATH) ?: '');
        if ($name === '' || $name === false) {
            $name = 'asset-' . substr(md5($url), 0, 8);
        }
        return AssetsHelper::prepareAssetName($name);
    }

    private function downloadToTemp(string $url): ?string
    {
        $tempPath = Craft::$app->getPath()->getTempPath() . '/influx-' . uniqid('', true);
        FileHelper::createDirectory(dirname($tempPath));

        try {
            $response = $this->client->get($url, [
                'sink'        => $tempPath,
                'http_errors' => false,
            ]);
        } catch (Throwable) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
            return null;
        }

        if ($response->getStatusCode() >= 300 || !is_file($tempPath)) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
            return null;
        }

        return $tempPath;
    }
}
