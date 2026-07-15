<?php

namespace GlueAgency\Influx\data;

use Cake\Utility\Hash;
use Craft;
use craft\helpers\UrlHelper;
use Generator;
use GlueAgency\Influx\exceptions\FeedFetchException;
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
 *   - a seen-URL set turns paginator cycles into a {@see FeedFetchException}
 *     instead of an infinite loop;
 *   - {@see MAX_PAGES} caps runaway chains that never repeat a URL.
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
            // Only the first request is authenticated. A paginated feed must
            // return next-page URLs that are fetchable as-is — the remote owns
            // whatever auth/token they carry. We never attach the link's own
            // credentials to a feed-supplied URL, which could point anywhere.
            $response = $this->data->fetchUrl($cursorUrl);
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

        return $this->normalizeUrl($url);
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
