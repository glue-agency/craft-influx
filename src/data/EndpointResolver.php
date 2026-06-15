<?php

namespace GlueAgency\Influx\data;

use craft\helpers\App;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\models\Link;

/**
 * Resolves a link's configured endpoints into concrete URLs ready to hand
 * to Guzzle.
 *
 * Encapsulates three concerns that used to be spread across DataService
 * and DebugService:
 *   - Per-site lookup (siteEndpoints[$siteHandle] → endpoint fallback).
 *   - `$ENV` / `@alias` resolution via Craft's App::parseEnv.
 *   - Token substitution for itemEndpoint (`{id}`, `{slug}`, `{site.handle}` etc.).
 *
 * Throws {@see FeedFetchException} for the specific failure modes a misconfigured
 * link can hit, so consumers get a clear message rather than a confused 4xx
 * from cURL.
 */
class EndpointResolver
{
    /**
     * Resolve the list endpoint for a (possibly site-specific) sync run.
     */
    public function listUrl(Link $link, ?string $siteHandle = null): string
    {
        $raw = $this->rawListEndpoint($link, $siteHandle);

        if (! $raw) {
            throw new FeedFetchException("Link '{$link->handle}' has no endpoint for site '{$siteHandle}'.");
        }

        return $this->parse($raw, $link->handle);
    }

    /**
     * Resolve the per-item endpoint, substituting `{tokenName}` placeholders
     * from the supplied map.
     *
     * @param array<string, string|int> $tokens
     */
    public function itemUrl(Link $link, array $tokens): string
    {
        if (! $link->itemEndpoint) {
            throw new FeedFetchException("Link '{$link->handle}' has no itemEndpoint configured.");
        }
        $url = $this->parse($link->itemEndpoint, $link->handle);

        foreach ($tokens as $name => $value) {
            $url = str_replace('{' . $name . '}', rawurlencode((string) $value), $url);
        }

        return $url;
    }

    /**
     * Best-effort URL for human-facing debug display. Returns the env-resolved
     * URL, or the raw token when the env isn't set — never throws, since this
     * is purely informational.
     */
    public function listUrlForDisplay(Link $link, ?string $siteHandle = null): ?string
    {
        $raw = $this->rawListEndpoint($link, $siteHandle);

        if (! $raw) {
            return null;
        }
        $parsed = App::parseEnv($raw);

        return is_string($parsed) && $parsed !== '' ? $parsed : $raw;
    }

    protected function rawListEndpoint(Link $link, ?string $siteHandle): ?string
    {
        if ($siteHandle && isset($link->siteEndpoints[$siteHandle])) {
            return $link->siteEndpoints[$siteHandle];
        }

        return $link->endpoint;
    }

    /**
     * Resolve `$ENV` references and reject `@alias` paths that didn't resolve
     * to a real URL — we'd rather throw than hand `$VAR` to cURL.
     */
    protected function parse(string $raw, string $linkHandle): string
    {
        $resolved = App::parseEnv($raw);

        if ($resolved === null) {
            throw new FeedFetchException(
                "Link '{$linkHandle}' endpoint '{$raw}' references an environment variable that isn't set."
            );
        }

        if (! is_string($resolved) || str_starts_with($resolved, '@')) {
            throw new FeedFetchException(
                "Link '{$linkHandle}' endpoint '{$raw}' uses an alias that isn't registered."
            );
        }

        return $resolved;
    }
}
