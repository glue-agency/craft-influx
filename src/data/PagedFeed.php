<?php

namespace GlueAgency\Influx\data;

use Cake\Utility\Hash;
use Craft;
use craft\helpers\UrlHelper;
use Generator;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\sync\RemoteItem;
use IteratorAggregate;
use Throwable;

/**
 * Lazily-paginated view over a link's feed: each iteration yields one
 * {@see FeedPage}, fetching on demand by following the link's paginatorNode.
 *
 * Owns the pagination safety rules in one place:
 *   - next URLs are normalized (Craft alias resolution; root-relative paths
 *     resolved against the endpoint host — mirrors feed-me's
 *     DataType::setupPaginationUrl());
 *   - next URLs inherit the endpoint's own query params (see below);
 *   - a seen-URL set turns paginator cycles into a {@see FeedFetchException}
 *     instead of an infinite loop;
 *   - {@see MAX_PAGES} caps runaway chains that never repeat a URL.
 *
 * Why next URLs inherit params: a site endpoint like `…/api/properties?language=fr`
 * often returns a `links.next` of `…/api/properties?page=2` — no language. Left
 * verbatim, pages 2+ would silently walk the DEFAULT-language feed, and since
 * per-language content is disjoint most of the FR feed would be unreachable.
 * So {@see nextUrl()} merges into every next URL any param MISSING from it,
 * sourced from the resolved configured endpoint's query string and from the
 * run's {@see $queryParams} (an offset preset's params, applied to the first
 * fetch only). Params already on the next URL always win — `page=2` survives,
 * and an API that echoes `language` keeps its own value.
 *
 * Consumed by both the sync run (all pages) and the debug inspector (breaks
 * after page one), so neither carries its own fetch/paginator-peek code.
 *
 * @implements IteratorAggregate<int, FeedPage>
 */
class PagedFeed implements IteratorAggregate
{
    /**
     * Hard ceiling on pages followed per run.
     */
    public const MAX_PAGES = 500;

    protected DataService $data;

    protected Link $link;

    protected ?string $siteHandle = null;

    protected array $queryParams = [];

    public function __construct(
        DataService $data,
        Link $link,
        ?string $siteHandle = null,
        array $queryParams = [],
    ) {
        $this->data = $data;
        $this->link = $link;
        $this->siteHandle = $siteHandle;
        $this->queryParams = $queryParams;
    }

    /**
     * @return Generator<int, FeedPage>
     * @throws FeedFetchException on fetch failures, paginator URL cycles, or
     * pagination running past {@see MAX_PAGES}.
     */
    public function getIterator(): Generator
    {
        $seenUrls = [];
        $number = 1;
        $cursor = null;

        while (true) {
            $page = $this->page($cursor, $number);

            yield $page;

            $nextUrl = $page->nextUrl;

            if ($nextUrl === null) {
                return;
            }

            if (isset($seenUrls[$nextUrl])) {
                throw new FeedFetchException("Pagination loop detected: '{$nextUrl}' was already fetched (paginator node '{$this->link->paginatorNode}').");
            }

            if (++$number > self::MAX_PAGES) {
                throw new FeedFetchException('Pagination exceeded ' . self::MAX_PAGES . " pages — aborting (paginator node '{$this->link->paginatorNode}').");
            }
            $seenUrls[$nextUrl] = true;
            $cursor = $nextUrl;
        }
    }

    /**
     * Fetch a single page: the initial page when $cursorUrl is null (the
     * configured endpoint + query params), or the page at a carried next-page
     * URL otherwise. The unit a resumable, page-per-step sync run advances one
     * at a time; {@see getIterator()} chains these for a synchronous walk.
     */
    public function page(?string $cursorUrl, int $number = 1): FeedPage
    {
        if ($cursorUrl === null) {
            $response = $this->data->fetch($this->link, $this->siteHandle, $this->queryParams);
        } else {
            $headers = [];
            $query = [];
            Influx::getInstance()->auth->applyToRequest($this->link, $headers, $query);
            $response = $this->data->fetchUrl($cursorUrl, $headers, $query);
        }

        $items = array_map(
            static fn(array $item): RemoteItem => new RemoteItem($item),
            $this->data->rootList($this->link, $response),
        );

        return new FeedPage(
            $number,
            $items,
            $this->nextUrl($response),
            $this->countAt($response, $this->link->totalCountNode),
            $this->countAt($response, $this->link->pageCountNode),
        );
    }

    /**
     * Read an integer count from the response at a Hash path — the total-item
     * or total-page count the feed reports. Null when the node isn't
     * configured, missing, or non-numeric.
     */
    protected function countAt(array $response, ?string $node): ?int
    {
        if (! $node) {
            return null;
        }

        $value = Hash::get($response, $node);

        return is_numeric($value) ? (int) $value : null;
    }

    protected function nextUrl(array $response): ?string
    {
        if (! $this->link->paginatorNode) {
            return null;
        }

        $next = Hash::get($response, $this->link->paginatorNode);

        if (! is_scalar($next)) {
            return null;
        }

        $url = trim((string) $next);

        if ($url === '' || $url === '0') {
            return null;
        }

        // Merge here (not in the iterator) so EVERY consumer — the synchronous
        // getIterator() walk AND the batchStep cursor path — carries the final,
        // param-complete URL, including into the cycle-detection seen-set.
        return $this->withPreservedParams($this->normalizeUrl($url));
    }

    /**
     * Fold the endpoint's own query params into a next URL that's missing them
     * (the language=fr → page=2 problem — see the class docblock). The next
     * URL's own params always win; only genuinely absent keys are added. The
     * URL's scheme/host/path/fragment are left untouched.
     *
     * The base params come from {@see baseQueryParams()} — a seam a unit test
     * can override to exercise the merge without a Craft boot.
     */
    protected function withPreservedParams(string $nextUrl): string
    {
        $base = $this->baseQueryParams();

        if (! $base) {
            return $nextUrl;
        }

        $parts = parse_url($nextUrl);

        // Unparseable — hand it on untouched; the fetch layer names the URL if
        // it fails.
        if ($parts === false) {
            return $nextUrl;
        }

        $existing = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $existing);
        }

        // The next URL's params win: union with $existing taking precedence.
        $merged = $existing + $base;

        if ($merged === $existing) {
            return $nextUrl;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $query = http_build_query($merged);

        return $scheme . $host . $port . $path . ($query !== '' ? '?' . $query : '') . $fragment;
    }

    /**
     * The query params a next URL should inherit when it's missing them: the
     * resolved configured endpoint's own query string, unioned with the run's
     * {@see $queryParams} (offset-preset params). The endpoint's params win over
     * the preset's on a key clash (the endpoint is the more specific source).
     *
     * Uses {@see \GlueAgency\Influx\data\EndpointResolver::listUrl()} — the same
     * authoritative resolution the run's first fetch uses — NOT the display
     * variant; neither applies auth (auth is added per-request in {@see page()}),
     * so no auth param leaks into a next URL. Overridable so the merge is
     * unit-testable without resolving a real endpoint.
     *
     * @return array<string, string>
     */
    protected function baseQueryParams(): array
    {
        $params = [];

        try {
            $endpoint = $this->data->endpoints()->listUrl($this->link, $this->siteHandle);
            $query = parse_url($endpoint, PHP_URL_QUERY);

            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
            }
        } catch (Throwable) {
            // No resolvable endpoint (misconfigured/env-less) — the first fetch
            // surfaces that; here we simply contribute no base params.
        }

        // Cast the preset's scalar values so the union is a uniform string map.
        $preset = array_map(static fn($value): string => (string) $value, $this->queryParams);

        return $params + $preset;
    }

    /**
     * Make a paginator value fetchable: resolve Craft aliases, give
     * protocol-relative URLs a scheme, and resolve root-relative paths
     * ('/items?page=2') against the endpoint's host — APIs that return
     * paths instead of absolute URLs are common.
     */
    protected function normalizeUrl(string $url): string
    {
        $url = (string) Craft::getAlias($url);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if (str_starts_with($url, '/')) {
            $base = $this->data->endpoints()->listUrlForDisplay($this->link, $this->siteHandle);

            if ($base !== null) {
                try {
                    return rtrim(UrlHelper::hostInfo($base), '/') . $url;
                } catch (Throwable) {
                    // Base wasn't a parsable URL — hand the raw value to the
                    // fetch layer, whose error message names the URL.
                }
            }
        }

        return $url;
    }
}
