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
     *
     * The meta envelope is declared ONCE, defaulted to "nothing fetched", and
     * then either stamped with the failure or filled in from the page — a failed
     * fetch and a successful one can't describe the feed with different keys.
     *
     * An offset preset that won't resolve rides the same `error` key rather than
     * throwing: a dry run exists to show the operator what a real run would do,
     * and "your preset is broken" is precisely that. A sync run fails its log
     * instead ({@see \GlueAgency\Influx\services\SynchronizationService::syncLink()}).
     */
    public function inspectSite(Link $link, ?string $siteHandle, int $limit, ?string $offset = null): Generator
    {
        $plugin = Influx::getInstance();
        $target = $plugin->targets->forLink($link);

        $matchAttr = $link->matchAttribute();
        $matchNode = $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null;

        $queryParams = [];
        $offsetLabel = null;
        $offsetError = null;

        try {
            [$queryParams, $offsetLabel] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];
        } catch (Throwable $e) {
            $offsetError = $e->getMessage();
        }

        if (! $target) {
            yield [
                'type' => 'error',
                'data' => [
                    'message' => "No element target registered for '{$link->elementType}'.",
                ],
            ];

            return;
        }

        $meta = [
            'siteHandle'     => $siteHandle,
            'url'            => $plugin->data->endpoints()->listUrlForDisplay($link, $siteHandle, $queryParams),
            'itemsOnPage'    => 0,
            'paginatorNode'  => $link->paginatorNode,
            'paginatorValue' => null,
            'totalCount'     => null,
            'pageCount'      => null,
            'limit'          => $limit,
            'matchAttribute' => $matchAttr,
            'matchNode'      => $matchNode,
            'offset'         => $offset,
            'offsetLabel'    => $offsetLabel,
            'offsetQuery'    => $queryParams,
            'error'          => null,
        ];

        if ($offsetError !== null) {
            $meta['error'] = $offsetError;

            yield ['type' => 'meta', 'data' => $meta];

            return;
        }

        $firstPage = null;

        try {
            foreach ($plugin->data->pages($link, $siteHandle, $queryParams) as $page) {
                $firstPage = $page;

                break;
            }
        } catch (Throwable $e) {
            $meta['error'] = $e->getMessage();

            yield ['type' => 'meta', 'data' => $meta];

            return;
        }

        if ($firstPage) {
            $meta['itemsOnPage'] = count($firstPage->items);
            $meta['paginatorValue'] = $firstPage->nextUrl;
            $meta['totalCount'] = $firstPage->totalCount;
            $meta['pageCount'] = $firstPage->pageCount;
        }

        yield ['type' => 'meta', 'data' => $meta];

        if (! $firstPage) {
            return;
        }

        foreach (array_slice($firstPage->items, 0, $limit) as $item) {
            yield [
                'type' => 'item',
                'data' => $plugin->inspector->inspectWithTarget($link, $target, $item->raw(), $siteHandle),
            ];
        }
    }
}
