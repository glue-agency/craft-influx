<?php

namespace GlueAgency\Influx\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use GlueAgency\Influx\data\EndpointResolver;
use GlueAgency\Influx\data\FeedInspector;
use GlueAgency\Influx\data\FeedPage;
use GlueAgency\Influx\data\PagedFeed;
use GlueAgency\Influx\exceptions\FeedFetchException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches JSON from a link's endpoint. JSON only by design.
 *
 * Endpoints resolve to either an HTTP(S) URL or a local file path — the
 * latter happens when the configured endpoint uses a Craft `@alias` that
 * expands to a filesystem path (e.g. `@data/vub.json`). Local paths skip
 * Guzzle and are read straight off disk.
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

        if ($this->isLocalPath($url)) {
            return $this->read($url);
        }

        $headers = [];
        $query = $queryParams;
        Influx::getInstance()->auth->applyToRequest($link, $headers, $query);

        return $this->get($url, $headers, $query);
    }

    public function fetchOne(Link $link, array $tokens): array
    {
        $url = $this->endpoints->itemUrl($link, $tokens);

        if ($this->isLocalPath($url)) {
            return $this->read($url);
        }

        $headers = [];
        $query = [];
        Influx::getInstance()->auth->applyToRequest($link, $headers, $query);

        return $this->get($url, $headers, $query);
    }

    public function fetchUrl(string $url, array $headers = [], array $query = []): array
    {
        return $this->get($url, $headers, $query);
    }

    /**
     * Fetch the link's endpoint once and hand the decoded response to
     * {@see FeedInspector} to suggest a rootNode, paginatorNode, sample item,
     * and starter mappings. Powers the "Fetch sample" button on the CP edit
     * screen; the shape-walking heuristics live in the inspector, this just
     * owns the fetch.
     *
     * @return array see {@see FeedInspector::report()}
     */
    public function inspect(Link $link): array
    {
        // Sample fetches aren't tied to a site. A link configured with only
        // site-specific endpoints has no base endpoint to fall back to, so
        // sample against the first site endpoint instead of erroring out.
        // Real sync runs stay strict — they resolve their own site handle.
        $siteHandle = null;

        if (! $link->endpoint) {
            $siteHandle = $link->siteHandles()[0] ?? null;
        }

        $response = $this->fetch($link, $siteHandle);

        return (new FeedInspector())->report(
            $link,
            $response,
            $this->endpoints->listUrlForDisplay($link, $siteHandle),
        );
    }

    public function rootList(Link $link, array $response): array
    {
        if (! $link->rootNode) {
            return is_array($response) ? array_values($response) : [];
        }

        $value = Hash::get($response, $link->rootNode);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Lazily-paginated view over the link's feed: pages fetch on demand as
     * the iterator advances, following the link's paginatorNode with cycle
     * and runaway-chain guards built in.
     */
    public function pages(Link $link, ?string $siteHandle = null, array $queryParams = []): PagedFeed
    {
        return new PagedFeed($this, $link, $siteHandle, $queryParams);
    }

    /**
     * Fetch a single feed page — the initial page ($cursorUrl null) or the page
     * at a carried next-page URL. Powers the resumable, page-per-step queue job;
     * {@see pages()} is the synchronous full walk.
     */
    public function page(Link $link, ?string $siteHandle, ?string $cursorUrl, array $queryParams = [], int $number = 1): FeedPage
    {
        return (new PagedFeed($this, $link, $siteHandle, $queryParams))->page($cursorUrl, $number);
    }

    public function endpoints(): EndpointResolver
    {
        return $this->endpoints;
    }

    protected function get(string $url, array $headers = [], array $query = []): array
    {
        $options = ['headers' => $headers];

        // Only set Guzzle's `query` option when there are params to add. Passing
        // it (even an empty array) makes Guzzle rebuild the URI's query string
        // from that array, wiping any query already on the URL — which strips
        // the `?page=2` off a paginator's next-page link, re-fetches page 1, and
        // trips the "pagination loop detected" guard on the repeated next URL.
        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = $this->client->get($url, $options);
        } catch (GuzzleException $e) {
            throw new FeedFetchException(
                "GET {$url} failed: " . $e->getMessage(),
                previous: $e,
            );
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new FeedFetchException("Response from {$url} is not valid JSON.");
        }

        return $decoded;
    }

    protected function isLocalPath(string $url): bool
    {
        return ! preg_match('#^https?://#i', $url);
    }

    protected function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new FeedFetchException("File '{$path}' is not readable.");
        }

        $body = file_get_contents($path);

        if ($body === false) {
            throw new FeedFetchException("Failed to read '{$path}'.");
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new FeedFetchException("Contents of '{$path}' are not valid JSON.");
        }

        return $decoded;
    }
}
