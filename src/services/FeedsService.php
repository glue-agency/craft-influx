<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\helpers\FileHelper;
use Symfony\Component\Yaml\Yaml;
use TDM\Influx\Influx;
use TDM\Influx\models\Feed;
use yii\base\InvalidConfigException;

/**
 * Discovers and loads feed definitions from YAML files under
 * <config>/<settings.configDirectory>/*.yaml.
 *
 * Configs are not written back from PHP outside of `duplicate()`. They are
 * meant to live in version control.
 */
class FeedsService extends Component
{
    /** @var Feed[]|null cached, keyed by handle */
    private ?array $feeds = null;

    /**
     * @return Feed[] indexed by handle
     */
    public function all(): array
    {
        if ($this->feeds !== null) {
            return $this->feeds;
        }

        $this->feeds = [];

        $dir = Influx::getInstance()->getSettings()->absoluteConfigPath();

        if (!is_dir($dir)) {
            return $this->feeds;
        }

        $files = FileHelper::findFiles($dir, [
            'only'      => ['*.yaml', '*.yml'],
            'recursive' => false,
        ]);

        foreach ($files as $file) {
            $feed = $this->loadFile($file);
            if ($feed) {
                $this->feeds[$feed->handle] = $feed;
            }
        }

        ksort($this->feeds);

        return $this->feeds;
    }

    public function getByHandle(string $handle): ?Feed
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * Find the first feed whose target element type and criteria claim this
     * element. Used by the per-entry "Sync from remote" button and the
     * per-element sync endpoint.
     */
    public function findFeedForElement(ElementInterface $element): ?Feed
    {
        $targets = Influx::getInstance()->targets;

        foreach ($this->all() as $feed) {
            $target = $targets->forFeed($feed);
            if ($target && $target->claimsElement($feed, $element)) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Duplicate a feed file on disk under a new handle. The caller is
     * responsible for committing the new file to version control.
     */
    public function duplicate(string $sourceHandle, string $newHandle, ?string $newName = null): Feed
    {
        $source = $this->getByHandle($sourceHandle);

        if (!$source || !$source->sourceFile || !is_file($source->sourceFile)) {
            throw new InvalidConfigException("Feed '{$sourceHandle}' not found.");
        }

        if ($this->getByHandle($newHandle)) {
            throw new InvalidConfigException("A feed with handle '{$newHandle}' already exists.");
        }

        $config = Yaml::parseFile($source->sourceFile) ?: [];
        $config['handle'] = $newHandle;
        $config['name'] = $newName ?? ($source->name . ' (copy)');

        $targetPath = dirname($source->sourceFile) . DIRECTORY_SEPARATOR . $newHandle . '.yaml';
        file_put_contents($targetPath, Yaml::dump($config, 6, 2));

        // Reset cache so the new feed is picked up.
        $this->feeds = null;

        return $this->getByHandle($newHandle)
            ?? throw new InvalidConfigException("Failed to re-load duplicated feed '{$newHandle}'.");
    }

    private function loadFile(string $path): ?Feed
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable $e) {
            Craft::error("Influx: failed to parse {$path}: " . $e->getMessage(), __METHOD__);
            return null;
        }

        if (!is_array($data)) {
            Craft::warning("Influx: {$path} did not parse to an array.", __METHOD__);
            return null;
        }

        // Default the handle to the filename if not explicit.
        $data['handle'] = $data['handle'] ?? pathinfo($path, PATHINFO_FILENAME);

        $feed = new Feed($data);
        $feed->sourceFile = $path;

        if (!$feed->validate()) {
            Craft::warning(
                "Influx: feed at {$path} has validation errors: "
                . json_encode($feed->getErrors()),
                __METHOD__
            );
            return null;
        }

        return $feed;
    }
}
