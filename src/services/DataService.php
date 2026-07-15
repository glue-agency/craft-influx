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
use GuzzleHttp\Exception\RequestException;

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
        $followRedirects = Influx::getInstance()->getSettings()->followRedirects;

        $this->client = Craft::createGuzzleClient([
            'http_errors'     => true,
            'timeout'         => 30,
            'allow_redirects' => $followRedirects
                ? ['max' => 5, 'strict' => true, 'protocols' => ['http', 'https']]
                : false,
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

        $auth = Influx::getInstance()->auth->requestAuth($link);

        return $this->get($url, $auth['headers'], array_merge($queryParams, $auth['query']));
    }

    public function fetchOne(Link $link, array $tokens): array
    {
        $url = $this->endpoints->itemUrl($link, $tokens);

        if ($this->isLocalPath($url)) {
            return $this->read($url);
        }

        $auth = Influx::getInstance()->auth->requestAuth($link);

        return $this->get($url, $auth['headers'], $auth['query']);
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

        // When we have params to add (query-string auth, offset presets), merge
        // them onto whatever query the URL already carries and let Guzzle
        // rebuild the URI. Guzzle's `query` option REPLACES the URI query, so
        // passing only our params would strip the endpoint's own (`?format=json`)
        // or a paginator cursor's (`?page=2`) — re-fetching page 1 and tripping
        // the loop guard. Our params win on collision. When there's nothing to
        // add, the URL is passed through untouched.
        if ($query !== []) {
            [$base, $existing] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($existing, $urlParams);
            $options['query'] = array_merge($urlParams, $query);
            $url = $base;
        }

        try {
            $response = $this->client->get($url, $options);
        } catch (GuzzleException $e) {
            // Never surface Guzzle's raw message: for query-string auth it
            // embeds the effective URI (…?api_key=SECRET…), which Guzzle does
            // not redact. Report the query-less URL + status only; the full
            // exception is chained as `previous` for the server-side log.
            $status = $e instanceof RequestException && $e->getResponse()
                ? ' (HTTP ' . $e->getResponse()->getStatusCode() . ')'
                : '';

            throw new FeedFetchException("GET {$url} failed{$status}.", previous: $e);
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
