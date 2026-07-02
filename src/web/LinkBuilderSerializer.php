<?php

namespace GlueAgency\Influx\web;

use craft\elements\Entry;
use GlueAgency\Influx\models\Link;

/**
 * Marshals a {@see Link} to and from the JSON wire shape the LinkBuilder Vue
 * SPA consumes — the single authority for that contract (the JS side documents
 * it in `builder/types.js` and asserts it against the committed fixture in
 * `src/web/assets/links/tests/fixtures/link-payload.json`).
 *
 * Extracted from the model so {@see Link} stays a plain state object: the SPA's
 * serialization concern lives here, next to {@see LogPresenter} and
 * {@see ItemRowPresenter}, the plugin's other web-facing shapers. The
 * round-trip is pinned by {@see \GlueAgency\Influx\Tests\unit\models\LinkBuilderPayloadTest}
 * (PHP side) and the JS contract test (SPA side) against the same fixture.
 *
 * Consumed by {@see \GlueAgency\Influx\services\LinkBuilderService}, which holds
 * the one shared instance and calls through it on bootstrap / save.
 */
class LinkBuilderSerializer
{
    /**
     * Marshal a link into the JSON wire shape the LinkBuilder SPA consumes.
     *
     * Array-y attrs are cast to objects so empty ones JSON-encode as `{}` (the
     * store treats them as keyed maps, not lists).
     */
    public function toArray(Link $link): array
    {
        return [
            'id'              => $link->id,
            'uid'             => $link->uid,
            'handle'          => $link->handle ?? '',
            'name'            => $link->name ?? '',
            'elementType'     => $link->elementType ?: Entry::class,
            'elementCriteria' => (object) ($link->elementCriteria ?? []),
            'endpoint'        => $link->endpoint,
            'itemEndpoint'    => $link->itemEndpoint,
            'siteEndpoints'   => $link->getSiteEndpoints(),
            'offset'          => (object) ($link->offset ?? []),
            'processing'      => array_values($link->processing ?? []),
            'rootNode'        => $link->rootNode,
            'paginatorNode'   => $link->paginatorNode,
            'totalCountNode'  => $link->totalCountNode,
            'pageCountNode'   => $link->pageCountNode,
            'mappings'        => (object) ($link->mappings ?? []),
            'match'           => (object) ($link->match ?? []),
            'auth'            => (object) ($link->auth ?? []),
            'backup'          => (bool) $link->backup,
        ];
    }

    /**
     * Apply a builder JSON payload onto a link. Mirrors the shape produced by
     * {@see toArray()}. Unknown keys are silently dropped — Yii's
     * `setAttributes(..., $safeOnly = true)` would do this for us, but we want
     * to coerce a few fields (objects → arrays, trimming strings) before they
     * hit the model.
     *
     * Site endpoints go through the model's {@see Link::setSiteEndpoints()}
     * accessor (its Yii magic setter) so the normalized list shape is enforced.
     */
    public function apply(Link $link, array $payload): void
    {
        $strOrNull = static fn(mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $link->handle = (string) ($payload['handle'] ?? $link->handle);
        $link->name = (string) ($payload['name'] ?? $link->name);
        $link->elementType = (string) ($payload['elementType'] ?? $link->elementType);

        $link->elementCriteria = (array) ($payload['elementCriteria'] ?? []);
        $link->endpoint = $strOrNull($payload['endpoint'] ?? null);
        $link->itemEndpoint = $strOrNull($payload['itemEndpoint'] ?? null);
        $link->setSiteEndpoints($payload['siteEndpoints'] ?? []);
        $link->offset = (array) ($payload['offset'] ?? []);
        $link->processing = array_values((array) ($payload['processing'] ?? []));
        $link->rootNode = $strOrNull($payload['rootNode'] ?? null);
        $link->paginatorNode = $strOrNull($payload['paginatorNode'] ?? null);
        $link->totalCountNode = $strOrNull($payload['totalCountNode'] ?? null);
        $link->pageCountNode = $strOrNull($payload['pageCountNode'] ?? null);
        $link->mappings = (array) ($payload['mappings'] ?? []);
        $link->match = (array) ($payload['match'] ?? []);
        $link->auth = (array) ($payload['auth'] ?? []);
        $link->backup = (bool) ($payload['backup'] ?? false);
    }
}
