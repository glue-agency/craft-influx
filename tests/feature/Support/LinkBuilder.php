<?php

namespace TDM\Influx\Tests\feature\Support;

use TDM\Influx\Influx;
use TDM\Influx\models\Link;

/**
 * Build + persist a Link in Project Config for the duration of a feature test.
 *
 * Encapsulates the boilerplate so each test reads as: "given a link mapping
 * X to Y, when sync runs, then ..." instead of 30 lines of config wiring.
 */
final class LinkBuilder
{
    public string $handle = 'articles';
    public string $name = 'Articles';
    public string $elementType = \craft\elements\Entry::class;
    public array $elementCriteria = ['section' => 'articles', 'type' => 'article'];
    public string $endpoint = 'https://example.test/articles';
    public ?string $itemEndpoint = null;
    public array $siteEndpoints = [];
    public ?string $rootNode = null;
    public ?string $paginatorNode = null;
    public array $match = ['attribute' => 'importId'];
    public array $mappings = [];
    public array $processing = ['create', 'update'];
    public array $ago = [];

    public static function articles(): self
    {
        $b = new self();
        $b->mappings = [
            'importId' => ['node' => 'id'],
            'title'    => ['node' => 'title'],
            'slug'     => ['node' => 'slug'],
        ];
        return $b;
    }

    public function withMappings(array $mappings): self
    {
        $this->mappings = $mappings + $this->mappings;
        return $this;
    }

    public function withProcessing(array $processing): self
    {
        $this->processing = $processing;
        return $this;
    }

    public function withSiteEndpoints(array $siteEndpoints): self
    {
        $this->siteEndpoints = $siteEndpoints;
        return $this;
    }

    public function withPaginator(string $node): self
    {
        $this->paginatorNode = $node;
        return $this;
    }

    public function withAgo(array $ago): self
    {
        $this->ago = $ago;
        return $this;
    }

    public function save(): Link
    {
        $link = new Link();
        foreach (get_object_vars($this) as $prop => $value) {
            if (property_exists($link, $prop)) {
                $link->$prop = $value;
            }
        }

        Influx::getInstance()->links->saveLink($link, runValidation: false);
        return Influx::getInstance()->links->getLinkByHandle($this->handle);
    }
}
