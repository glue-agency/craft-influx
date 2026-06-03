<?php

namespace TDM\Influx\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use TDM\Influx\models\Link;
use TDM\Influx\exceptions\FeedFetchException;

/**
 * Fetches JSON from a link's endpoint. JSON only by design.
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

    public function fetch(Link $link, ?string $siteHandle = null, array $queryParams = []): array
    {
        $url = $siteHandle
            ? $link->endpointForSite($siteHandle)
            : ($link->endpoint ? App::parseEnv($link->endpoint) : null);

        if (!$url) {
            throw new FeedFetchException("Link '{$link->handle}' has no endpoint for site '{$siteHandle}'.");
        }

        return $this->get($url, $link->resolvedHeaders(), $queryParams);
    }

    public function fetchOne(Link $link, array $tokens, ?string $siteHandle = null): array
    {
        if (!$link->itemEndpoint) {
            throw new FeedFetchException("Link '{$link->handle}' has no itemEndpoint configured.");
        }

        $url = App::parseEnv($link->itemEndpoint);

        foreach ($tokens as $name => $value) {
            $url = str_replace('{' . $name . '}', rawurlencode((string)$value), $url);
        }

        if ($siteHandle) {
            $url = str_replace('{site}', rawurlencode($siteHandle), $url);
        }

        return $this->get($url, $link->resolvedHeaders());
    }

    public function fetchUrl(string $url, array $headers = []): array
    {
        return $this->get($url, $headers);
    }

    public function rootList(Link $link, array $response): array
    {
        if (!$link->rootNode) {
            return is_array($response) ? array_values($response) : [];
        }

        $value = Hash::get($response, $link->rootNode);

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
