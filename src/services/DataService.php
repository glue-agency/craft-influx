<?php

namespace TDM\Influx\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TDM\Influx\data\EndpointResolver;
use TDM\Influx\models\Link;
use TDM\Influx\exceptions\FeedFetchException;

/**
 * Fetches JSON from a link's endpoint. JSON only by design.
 */
class DataService extends Component
{
    protected Client $client;
    protected EndpointResolver $endpoints;

    public function init(): void
    {
        $this->client = Craft::createGuzzleClient([
            'http_errors' => true,
            'timeout'     => 30,
        ]);
        $this->endpoints = new EndpointResolver();

        parent::init();
    }

    public function fetch(Link $link, ?string $siteHandle = null, array $queryParams = []): array
    {
        $url = $this->endpoints->listUrl($link, $siteHandle);

        $headers = [];
        $query   = $queryParams;
        $link->applyAuth($headers, $query);

        return $this->get($url, $headers, $query);
    }

    public function fetchOne(Link $link, array $tokens, ?string $siteHandle = null): array
    {
        $url = $this->endpoints->itemUrl($link, $tokens, $siteHandle);

        $headers = [];
        $query   = [];
        $link->applyAuth($headers, $query);

        return $this->get($url, $headers, $query);
    }

    public function fetchUrl(string $url, array $headers = [], array $query = []): array
    {
        return $this->get($url, $headers, $query);
    }

    /**
     * Fetch the link's endpoint once and walk the resulting structure to
     * suggest a rootNode, paginatorNode, and a starter set of PlainText
     * mappings derived from the first item. Used by the "Fetch sample"
     * button on the CP edit screen.
     *
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
     */
    public function inspect(Link $link): array
    {
        $response = $this->fetch($link);

        $rootCandidates = $this->findArrayPaths($response, '', 3);
        $rootNode = $rootCandidates[0] ?? null;

        $paginatorCandidates = $this->findPaginatorPaths($response);
        $paginatorNode = $paginatorCandidates[0] ?? null;

        $list = $rootNode === null
            ? (is_array($response) ? array_values($response) : [])
            : (is_array(Hash::get($response, $rootNode)) ? array_values(Hash::get($response, $rootNode)) : []);

        $sampleItem = $list[0] ?? null;

        $flatNodes = [];
        $mappingSuggestions = [];
        if (is_array($sampleItem)) {
            foreach ($this->flattenLeafPaths($sampleItem, []) as $path) {
                $flatNodes[] = ['value' => $path, 'label' => str_replace('.', ' → ', $path)];
            }

            foreach ($sampleItem as $key => $value) {
                if (!is_string($key)) {
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

        return [
            'url'                     => $this->endpoints->listUrlForDisplay($link),
            'rootNode'                => $rootNode,
            'rootNodeCandidates'      => $rootCandidates,
            'paginatorNode'           => $paginatorNode,
            'paginatorNodeCandidates' => $paginatorCandidates,
            'sampleItem'              => is_array($sampleItem) ? $sampleItem : null,
            'mappingSuggestions'      => $mappingSuggestions,
            'flatNodes'               => $flatNodes,
        ];
    }

    /**
     * Walk a sample item and return every leaf-path as a dot-separated string.
     * Lists of objects expose only their first element's leaves (paths use
     * the parent key, not the numeric index, to keep mappings stable).
     *
     * @return list<string>
     */
    private function flattenLeafPaths(mixed $value, array $prefix): array
    {
        if (!is_array($value)) {
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
            if (!is_string($key)) {
                continue;
            }
            $childPrefix = array_merge($prefix, [$key]);
            if (is_array($child) && !empty($child) && !array_is_list($child)) {
                foreach ($this->flattenLeafPaths($child, $childPrefix) as $p) {
                    $paths[] = $p;
                }
            } else {
                $paths[] = implode('.', $childPrefix);
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
    private function findArrayPaths(mixed $value, string $prefix, int $depth): array
    {
        $paths = [];

        if (!is_array($value)) {
            return $paths;
        }

        if ($this->looksLikeListOfObjects($value)) {
            $paths[] = $prefix;
        }

        if ($depth <= 0 || array_is_list($value)) {
            return $paths;
        }

        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                continue;
            }
            $childPath = $prefix === '' ? $key : ($prefix . '.' . $key);
            foreach ($this->findArrayPaths($child, $childPath, $depth - 1) as $p) {
                $paths[] = $p;
            }
        }

        return $paths;
    }

    private function looksLikeListOfObjects(array $value): bool
    {
        if (!array_is_list($value) || empty($value)) {
            return false;
        }
        $first = $value[0];
        return is_array($first) && !array_is_list($first);
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
    private function findPaginatorPaths(array $response): array
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

        // Walk the whole response (skipping the iterable root list, which is
        // identified by findArrayPaths) and add any string leaves.
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
    private function stringLeafPaths(mixed $value, array $prefix): array
    {
        if (!is_array($value)) {
            return [$prefix ? implode('.', $prefix) : ''];
        }

        if (empty($value)) {
            return [];
        }

        if (array_is_list($value)) {
            $first = $value[0] ?? null;
            if (is_array($first) && !array_is_list($first)) {
                return [];
            }
            return [$prefix ? implode('.', $prefix) : ''];
        }

        $paths = [];
        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                continue;
            }
            foreach ($this->stringLeafPaths($child, array_merge($prefix, [$key])) as $p) {
                $paths[] = $p;
            }
        }
        return $paths;
    }

    public function rootList(Link $link, array $response): array
    {
        if (!$link->rootNode) {
            return is_array($response) ? array_values($response) : [];
        }

        $value = Hash::get($response, $link->rootNode);

        return is_array($value) ? array_values($value) : [];
    }

    public function endpoints(): EndpointResolver
    {
        return $this->endpoints;
    }

    private function get(string $url, array $headers = [], array $query = []): array
    {
        try {
            $response = $this->client->get($url, [
                'headers' => $headers,
                'query'   => $query,
            ]);
        } catch (GuzzleException $e) {
            throw new FeedFetchException(
                "GET {$url} failed: " . $e->getMessage(),
                previous: $e,
            );
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new FeedFetchException("Response from {$url} is not valid JSON.");
        }

        return $decoded;
    }
}
