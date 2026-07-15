<?php

namespace GlueAgency\Influx\services;

use craft\base\Component;
use Generator;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use Throwable;

/**
 * The Links overview "Debug" view: a live, strict dry-run of a link's first
 * page of remote items, streamed as Server-Sent Events. It fetches and paginates
 * exactly like the real sync (stopping after the first page), then hands each
 * item to {@see InspectorService} for the per-item inspection both this view and
 * the log drill-down share. Writes nothing — no logs, no element saves, no
 * cooldown marks.
 */
class DebugService extends Component
{
    public const DEFAULT_LIMIT = 10;

    /**
     * Per-site dry-run, yielding events suitable for Server-Sent Events:
     *
     *   ['type' => 'meta',  'data' => [...]]   — site metadata, once at start
     *   ['type' => 'item',  'data' => [...]]   — one per processed item
     *   ['type' => 'error', 'data' => [...]]   — non-recoverable fetch/setup error
     *
     * The generator finishes naturally when the first page is exhausted or the
     * limit is reached; callers should send their own "done" sentinel.
     */
    public function streamSite(Link $link, ?string $siteHandle, int $limit, ?string $offset = null): Generator
    {
        $plugin = Influx::getInstance();
        $target = $plugin->targets->forLink($link);

        $matchAttr = $link->matchAttribute();
        $matchNode = $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null;

        [$queryParams, $offsetLabel] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        if (! $target) {
            yield [
                'type' => 'error',
                'data' => [
                    'message' => "No element target registered for '{$link->elementType}'.",
                ],
            ];

            return;
        }

        $url = $plugin->data->endpoints()->listUrlForDisplay($link, $siteHandle, $queryParams);

        // Same iterator the sync run walks — but stop after the first page
        $firstPage = null;

        try {
            foreach ($plugin->data->pages($link, $siteHandle, $queryParams) as $page) {
                $firstPage = $page;

                break;
            }
        } catch (Throwable $e) {
            yield [
                'type' => 'meta',
                'data' => [
                    'siteHandle'     => $siteHandle,
                    'url'            => $url,
                    'itemsOnPage'    => 0,
                    'paginatorNode'  => $link->paginatorNode,
                    'paginatorValue' => null,
                    'limit'          => $limit,
                    'matchAttribute' => $matchAttr,
                    'matchNode'      => $matchNode,
                    'offset'         => $offset,
                    'offsetLabel'    => $offsetLabel,
                    'offsetQuery'    => $queryParams,
                    'error'          => $e->getMessage(),
                ],
            ];

            return;
        }

        yield [
            'type' => 'meta',
            'data' => [
                'siteHandle'     => $siteHandle,
                'url'            => $url,
                'itemsOnPage'    => $firstPage ? count($firstPage->items) : 0,
                'paginatorNode'  => $link->paginatorNode,
                'paginatorValue' => $firstPage?->nextUrl,
                'totalCount'     => $firstPage?->totalCount,
                'pageCount'      => $firstPage?->pageCount,
                'limit'          => $limit,
                'matchAttribute' => $matchAttr,
                'matchNode'      => $matchNode,
                'offset'         => $offset,
                'offsetLabel'    => $offsetLabel,
                'offsetQuery'    => $queryParams,
                'error'          => null,
            ],
        ];

        if (! $firstPage) {
            return;
        }

        $index = 0;

        foreach (array_slice($firstPage->items, 0, $limit) as $item) {
            $row = $plugin->inspector->inspectWithTarget($link, $target, $item->raw(), $siteHandle);
            $row['index'] = $index++;
            yield ['type' => 'item', 'data' => $row];
        }
    }
}
