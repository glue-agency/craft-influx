<?php

namespace GlueAgency\Influx\data;

use GlueAgency\Influx\sync\RemoteItem;

/**
 * One fetched page of a paginated feed, as yielded by {@see PagedFeed}.
 * Treat as read-only.
 */
class FeedPage
{
    /** 1-based page number. */
    public int $number = 1;

    /** @var list<RemoteItem> The page's root-list items. */
    public array $items = [];

    /** Normalized next-page URL, or null when this is the last page. */
    public ?string $nextUrl = null;

    /** Total item count the feed reported (via the link's totalCountNode), or null. */
    public ?int $totalCount = null;

    /** Total page count the feed reported (via the link's pageCountNode), or null. */
    public ?int $pageCount = null;

    /** @param list<RemoteItem> $items */
    public function __construct(int $number, array $items, ?string $nextUrl, ?int $totalCount = null, ?int $pageCount = null)
    {
        $this->number = $number;
        $this->items = $items;
        $this->nextUrl = $nextUrl;
        $this->totalCount = $totalCount;
        $this->pageCount = $pageCount;
    }
}
