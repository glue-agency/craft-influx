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

    /** @param list<RemoteItem> $items */
    public function __construct(int $number, array $items, ?string $nextUrl)
    {
        $this->number = $number;
        $this->items = $items;
        $this->nextUrl = $nextUrl;
    }
}
