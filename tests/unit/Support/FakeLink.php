<?php

namespace GlueAgency\Influx\Tests\unit\Support;

use craft\elements\Entry;
use GlueAgency\Influx\models\Link;

/**
 * Lightweight Link factory for unit tests. Skips validation so we can build
 * the shape we want without configuring every required attribute.
 */
final class FakeLink
{
    public static function make(array $overrides = []): Link
    {
        $link = new Link();
        $link->handle = $overrides['handle'] ?? 'articles';
        $link->name = $overrides['name'] ?? 'Articles';
        $link->elementType = $overrides['elementType'] ?? Entry::class;
        $link->endpoint = $overrides['endpoint'] ?? 'https://example.test/articles';
        $link->mappings = $overrides['mappings'] ?? [];
        $link->match = $overrides['match'] ?? ['attribute' => 'importId'];
        $link->processing = $overrides['processing'] ?? ['create', 'update'];

        foreach ($overrides as $k => $v) {
            if (property_exists($link, $k)) {
                $link->$k = $v;
            }
        }

        return $link;
    }
}
