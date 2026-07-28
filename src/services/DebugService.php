<?php

namespace GlueAgency\Influx\services;

use craft\base\Component;
use Generator;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use Throwable;

/**
 * The Links overview "Debug" view: a strict dry-run of a link's first page of
 * remote items. It walks the same paginated iterator the real sync walks —
 * stopping after the first page — then hands each item to
 * {@see InspectorService} for the per-item inspection both this view and the
 * log drill-down share. Writes nothing — no logs, no element saves, no
 * cooldown marks.
 *
 * The dry-run is exposed as a generator, but nothing is streamed: the sole
 * caller, {@see \GlueAgency\Influx\controllers\LinksController::actionDebugInspect()},
 * buffers every yielded event and answers with one JSON response.
 */
class DebugService extends Component
{
    public const DEFAULT_LIMIT = 10;

    /**
     * Per-site dry-run, yielding typed event arrays:
     *
     *   ['type' => 'error', 'data' => [...]]   — no element target registered;
     *                                            nothing else follows
     *   ['type' => 'meta',  'data' => [...]]   — feed metadata, once; a failed
     *                                            fetch rides in its `error` key
     *   ['type' => 'item',  'data' => [...]]   — one per processed item
     *
     * A generator, so it can walk the sync's own paginated iterator and stop
     * after the first page; it finishes when that page is exhausted or the limit
     * is reached. Nothing is streamed — the caller buffers every event into a
     * single JSON response — so there is no "done" sentinel.
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
