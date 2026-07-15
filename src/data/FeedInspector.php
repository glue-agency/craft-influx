<?php

namespace GlueAgency\Influx\data;

use Cake\Utility\Hash;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;

/**
 * Given a decoded feed response, suggests its shape for the CP "Fetch sample"
 * flow: a rootNode + paginatorNode candidate list, a sample item, a starter
 * set of PlainText mapping suggestions, and the flat node list the mapping
 * dropdowns offer.
 *
 * Pure analysis — no transport. {@see \GlueAgency\Influx\services\DataService::inspect()}
 * fetches the feed and hands the decoded response here, keeping DataService to
 * "JSON fetch only by design" and isolating the tree-walking heuristics for
 * focused testing.
 */
class FeedInspector
{
    /**
     * Build the sample report for a link from an already-fetched response.
     *
     * @param string $url The display URL the sample was fetched from.
     * @return array{
     *   url: string,
     *   rootNode: ?string,
     *   rootNodeCandidates: string[],
     *   paginatorNode: ?string,
     *   paginatorNodeCandidates: string[],
     *   sampleItem: ?array,
     *   mappingSuggestions: list<array{field: string, type: string, node: string}>,
     *   flatNodes: list<array{value: string, label: string}>,
     * }
     * @throws InfluxException when the response shape can't yield a list of items.
     */
    public function report(Link $link, array $response, string $url): array
    {
        $rootCandidates = $this->findArrayPaths($response, '', 3);
        $paginatorCandidates = $this->findPaginatorPaths($response);

        // Don't auto-guess the root node; require it (or a top-level list) or error out
        $rootNode = $link->rootNode;
        $paginatorNode = $link->paginatorNode;

        if ($rootNode === null) {
            if (! is_array($response) || ! array_is_list($response)) {
                throw new InfluxException(
                    "Configure a root node before fetching a sample — the response is not a top-level array, so Influx needs to know which key inside the response holds the list of items."
                );
            }
            $list = $response;
        } else {
            $listSource = Hash::get($response, $rootNode);

            if (! is_array($listSource)) {
                throw new InfluxException(
                    "Root node '{$rootNode}' does not resolve to an array in the response."
                );
            }
            $list = array_values($listSource);
        }

        $sampleItem = $list[0] ?? null;

        $flatNodes = [];
        $mappingSuggestions = [];

        if (is_array($sampleItem)) {
            foreach ($this->flattenLeafPaths($sampleItem, []) as $path) {
                $flatNodes[] = [
                    'value' => $path,
                    'label' => $path . $this->nodeDataSuffix($sampleItem, $path),
                ];
            }

            foreach ($sampleItem as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    $mappingSuggestions[] = [
                        'field' => $key,
                        'type'  => 'PlainText',
                        'node'  => $key,
                    ];
                }
            }
        }

        // Response-level scalar leaves — total-count / page-count node candidates
        $countCandidates = array_values(array_filter(
            $this->stringLeafPaths($response, []),
            static fn(string $path): bool => $path !== '',
        ));

        return [
            'url'                     => $url,
            'rootNode'                => $rootNode,
            'rootNodeCandidates'      => $rootCandidates,
            'paginatorNode'           => $paginatorNode,
            'paginatorNodeCandidates' => $paginatorCandidates,
            'countNodeCandidates'     => $countCandidates,
            'sampleItem'              => is_array($sampleItem) ? $sampleItem : null,
            'mappingSuggestions'      => $mappingSuggestions,
            'flatNodes'               => $flatNodes,
        ];
    }

    /**
     * " <data>" label suffix for a flat node: the sample item's value at
     * the path, single-line, truncated to 30 chars — so the mapping
     * dropdowns preview real feed data next to each node. The SPA renders
     * labels as escaped text, so the angle brackets are safe. Null/empty
     * values get no suffix.
     */
    protected function nodeDataSuffix(array $sampleItem, string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Resolve like the sync pipeline so the preview matches what a mapping reads
        $value = (new RemoteItem($sampleItem))->get($path);

        if ($value === null) {
            return '';
        }

        $preview = trim(preg_replace('/\s+/u', ' ', $this->previewValue($value)) ?? '');

        if ($preview === '') {
            return '';
        }

        if (mb_strlen($preview) > 30) {
            $preview = mb_substr($preview, 0, 30) . '…';
        }

        return " <{$preview}>";
    }

    /**
     * Single-line preview of a sample value. Arrays/objects collapse to
     * their first item — `a, …` for lists, `key: value, …` for objects —
     * recursively, so nested containers still end in something readable.
     */
    protected function previewValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return '';
            }
            $firstKey = array_key_first($value);
            $first = $this->previewValue($value[$firstKey]);
            $prefix = is_int($firstKey) ? '' : $firstKey . ': ';
            $more = count($value) > 1 ? ', …' : '';

            return $prefix . $first . $more;
        }

        return '';
    }

    /**
     * Walk a sample item and return every leaf-path as a dot-separated string.
     *
     * Nested objects contribute their leaves only. List-valued keys are
     * nodes themselves (relation mappings consume whole arrays) and, when
     * they hold objects, additionally expose their element leaves under the
     * parent key with the index collapsed away (`directors.role.key`) —
     * {@see \GlueAgency\Influx\sync\RemoteItem::get()} fans such reads out
     * over every list element at sync time.
     *
     * @return list<string>
     */
    protected function flattenLeafPaths(mixed $value, array $prefix): array
    {
        if (! is_array($value)) {
            return [$prefix ? implode('.', $prefix) : ''];
        }

        if (empty($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return $this->flattenLeafPaths($value[0], $prefix);
        }

        $paths = [];

        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                continue;
            }
            $childPrefix = array_merge($prefix, [$key]);

            // Nested object: only its leaves are nodes.
            if (is_array($child) && ! empty($child) && ! array_is_list($child)) {
                foreach ($this->flattenLeafPaths($child, $childPrefix) as $p) {
                    $paths[] = $p;
                }

                continue;
            }

            $paths[] = implode('.', $childPrefix);

            if (is_array($child) && $this->looksLikeListOfObjects($child)) {
                // List-of-objects: expose the first element's leaves under the
                // parent key with the index collapsed; RemoteItem fans out at sync
                foreach ($this->flattenLeafPaths($child[0], $childPrefix) as $p) {
                    $paths[] = $p;
                }
            }
        }

        return $paths;
    }

    /**
     * Walk a decoded JSON structure and collect dot-paths to lists-of-objects
     * (i.e. probable rootNode locations). The empty path means the response
     * itself is the list.
     *
     * @return string[]
     */
    protected function findArrayPaths(mixed $value, string $prefix, int $depth): array
    {
        $paths = [];

        if (! is_array($value)) {
            return $paths;
        }

        if ($this->looksLikeListOfObjects($value)) {
            $paths[] = $prefix;
        }

        if ($depth <= 0 || array_is_list($value)) {
            return $paths;
        }

        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                continue;
            }
            $childPath = $prefix === '' ? $key : ($prefix . '.' . $key);

            foreach ($this->findArrayPaths($child, $childPath, $depth - 1) as $p) {
                $paths[] = $p;
            }
        }

        return $paths;
    }

    protected function looksLikeListOfObjects(array $value): bool
    {
        if (! array_is_list($value) || empty($value)) {
            return false;
        }
        $first = $value[0];

        return is_array($first) && ! array_is_list($first);
    }

    /**
     * Best-effort list of paginator-node candidates. Starts with a heuristic
     * ordering for the most common shapes (so the first hit is sensibly
     * pre-selected), then unions with every leaf-path on the response whose
     * value is a non-empty string — that way an unconventional shape can
     * still be picked from the dropdown after a sample fetch.
     *
     * @return string[]
     */
    protected function findPaginatorPaths(array $response): array
    {
        $preferred = [
            'next', 'next_url', 'nextPageUrl',
            'links.next', 'links.next_url',
            'meta.next', 'meta.next_url', 'meta.next_page_url',
            'paging.next', 'paging.next_url',
            'pagination.next', 'pagination.next_url',
        ];

        $hits = [];
        $seen = [];

        foreach ($preferred as $path) {
            $value = Hash::get($response, $path);

            if (is_string($value) && $value !== '') {
                $hits[] = $path;
                $seen[$path] = true;
            }
        }

        // Add any string leaves from the whole response (root list skipped by findArrayPaths)
        foreach ($this->stringLeafPaths($response, []) as $path) {
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $hits[] = $path;
            $seen[$path] = true;
        }

        return $hits;
    }

    /**
     * Walk an array and yield dot-paths to every scalar (or null) leaf. Skips
     * list-of-objects branches — those are root-node candidates, not paginator
     * candidates. Null/empty values are still listed: a key that's null on one
     * response may carry a real URL on another, so the user still needs to be
     * able to pick it.
     *
     * @return list<string>
     */
    protected function stringLeafPaths(mixed $value, array $prefix): array
    {
        if (! is_array($value)) {
            return [$prefix ? implode('.', $prefix) : ''];
        }

        if (empty($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $first = $value[0] ?? null;

            if (is_array($first) && ! array_is_list($first)) {
                return [];
            }

            return [$prefix ? implode('.', $prefix) : ''];
        }

        $paths = [];

        foreach ($value as $key => $child) {
            if (! is_string($key)) {
                continue;
            }

            foreach ($this->stringLeafPaths($child, array_merge($prefix, [$key])) as $p) {
                $paths[] = $p;
            }
        }

        return $paths;
    }
}
