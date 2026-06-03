<?php

namespace TDM\Influx\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TDM\Influx\models\Feed;
use TDM\Influx\exceptions\FeedFetchException;

/**
 * Fetches JSON from a feed endpoint. Only JSON is supported by design — the
 * whole point of moving off FeedMe is to drop XML/CSV/etc. complexity.
 */
class DataService extends Component
{
    protected Client $client;

    public function init(): void
    {
        $this->client = Craft::createGuzzleClient([
            'http_errors' => true,
            'timeout'     => 30,
        ]);

        parent::init();
    }

    /**
     * Fetch the list payload for a given site.
     *
     * @param Feed $feed
     * @param string|null $siteHandle null = use the feed's default endpoint
     * @param array $queryParams extra query string params (e.g. modified_since)
     * @return array Decoded JSON response
     */
    public function fetch(Feed $feed, ?string $siteHandle = null, array $queryParams = []): array
    {
        $url = $siteHandle
            ? $feed->endpointForSite($siteHandle)
            : ($feed->endpoint ? App::parseEnv($feed->endpoint) : null);

        if (!$url) {
            throw new FeedFetchException("Feed '{$feed->handle}' has no endpoint for site '{$siteHandle}'.");
        }

        return $this->get($url, $feed->resolvedHeaders(), $queryParams);
    }

    /**
     * Fetch a single remote resource using the feed's itemEndpoint pattern.
     * `{id}` (and any other token in $tokens) is substituted into the URL.
     */
    public function fetchOne(Feed $feed, array $tokens, ?string $siteHandle = null): array
    {
        if (!$feed->itemEndpoint) {
            throw new FeedFetchException("Feed '{$feed->handle}' has no itemEndpoint configured.");
        }

        $url = App::parseEnv($feed->itemEndpoint);

        foreach ($tokens as $name => $value) {
            $url = str_replace('{' . $name . '}', rawurlencode((string)$value), $url);
        }

        // If a site-specific base is configured, the itemEndpoint can still
        // contain {site} for per-site differentiation.
        if ($siteHandle) {
            $url = str_replace('{site}', rawurlencode($siteHandle), $url);
        }

        return $this->get($url, $feed->resolvedHeaders());
    }

    /**
     * Pull a list payload from a follow-up URL (cursor pagination). The next
     * URL typically already contains the pagination state.
     */
    public function fetchUrl(string $url, array $headers = []): array
    {
        return $this->get($url, $headers);
    }

    /**
     * Read the root list out of a response according to the feed's rootNode.
     */
    public function rootList(Feed $feed, array $response): array
    {
        if (!$feed->rootNode) {
            return is_array($response) ? array_values($response) : [];
        }

        $value = Hash::get($response, $feed->rootNode);

        return is_array($value) ? array_values($value) : [];
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
                previous: $e
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
