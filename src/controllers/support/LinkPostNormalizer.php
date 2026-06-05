<?php

namespace TDM\Influx\controllers\support;

use TDM\Influx\models\Link;

/**
 * Translates raw POST input from the CP edit form into the shape stored in
 * Project Config — mappings (recursive), auth, offset presets, siteEndpoints,
 * etc. Extracted from {@see \TDM\Influx\controllers\LinksController} so the
 * controller stays focused on routing / response handling.
 *
 * The form payload is shaped like:
 *
 *   handle, name, elementType: scalars
 *   endpoint, itemEndpoint, rootNode, paginatorNode: scalars (empty → null)
 *   elementCriteria: array
 *   auth: { type, token, header?, param? }
 *   siteEndpoints: [{ key, value }, ...]  (table)
 *   offset: [{ key, since, queryParam, format? }, ...]  (table)
 *   mappings: { handle: { node, default, options (json string), fields (json), nativeFields (json), type? } }
 *   processing: subset of {@see Link::ALL_PROCESSING}
 *   match.attribute: scalar
 *   backup: bool
 */
class LinkPostNormalizer
{
    /**
     * Populate the link from the form payload. Returns the same link instance
     * so the caller can chain into `LinksService::saveLink()`.
     */
    public function apply(Link $link, array $body): Link
    {
        $link->handle      = (string)($body['handle']      ?? $link->handle);
        $link->name        = (string)($body['name']        ?? $link->name);
        $link->elementType = (string)($body['elementType'] ?? $link->elementType);
        $link->endpoint    = $this->emptyToNull($body['endpoint']      ?? null);
        $link->itemEndpoint = $this->emptyToNull($body['itemEndpoint'] ?? null);
        $link->rootNode     = $this->emptyToNull($body['rootNode']      ?? null);
        $link->paginatorNode = $this->emptyToNull($body['paginatorNode'] ?? null);
        $link->backup       = (bool)($body['backup'] ?? false);

        $link->elementCriteria = array_filter(
            $this->arr($body['elementCriteria'] ?? null),
            fn($v) => $v !== '' && $v !== null,
        );

        $link->auth          = $this->auth($this->arr($body['auth'] ?? null));
        $link->siteEndpoints = $this->keyValueTable($this->arr($body['siteEndpoints'] ?? null));
        $link->mappings      = $this->mappings($this->arr($body['mappings'] ?? null));
        $link->offset        = $this->offset($this->arr($body['offset'] ?? null));
        $link->processing    = array_values(array_filter($this->arr($body['processing'] ?? null)));

        $matchAttribute = $body['match.attribute'] ?? ($body['match']['attribute'] ?? null);
        $link->match = $matchAttribute ? ['attribute' => $matchAttribute] : [];

        return $link;
    }

    /**
     * Build a minimal auth array from the form payload. Drops the field
     * entirely when type or token is empty.
     */
    public function auth(array $raw): array
    {
        $type = trim((string)($raw['type'] ?? ''));
        $token = trim((string)($raw['token'] ?? ''));

        if ($type === '' || $token === '') {
            return [];
        }

        $auth = ['type' => $type, 'token' => $token];

        if ($type === 'custom') {
            $header = trim((string)($raw['header'] ?? ''));
            if ($header !== '') {
                $auth['header'] = $header;
            }
        }
        if ($type === 'querystring') {
            $param = trim((string)($raw['param'] ?? ''));
            if ($param !== '') {
                $auth['param'] = $param;
            }
        }
        return $auth;
    }

    /**
     * Reduce a `[{ key: ..., value: ... }, ...]` table to a `[key => value]`
     * map, dropping empty rows.
     */
    public function keyValueTable(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $k = trim((string)($row['key'] ?? ''));
            $v = (string)($row['value'] ?? '');
            if ($k === '' || $v === '') {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Normalize the mappings form-payload into the recursive shape stored
     * in Project Config. Empty rows are dropped.
     */
    public function mappings(array $rows): array
    {
        $out = [];
        foreach ($rows as $handle => $row) {
            if (!is_string($handle) || !is_array($row)) {
                continue;
            }
            $entry = $this->mappingRow($row);
            if ($entry !== null) {
                $out[$handle] = $entry;
            }
        }
        return $out;
    }

    public function offset(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key        = trim((string)($row['key'] ?? ''));
            $since      = trim((string)($row['since'] ?? ''));
            $queryParam = trim((string)($row['queryParam'] ?? ''));

            if ($key === '' || $since === '' || $queryParam === '') {
                continue;
            }

            $entry = ['since' => $since, 'queryParam' => $queryParam];
            $format = trim((string)($row['format'] ?? ''));
            if ($format !== '') {
                $entry['format'] = $format;
            }
            $out[$key] = $entry;
        }
        return $out;
    }

    private function mappingRow(array $row): ?array
    {
        $node = trim((string)($row['node'] ?? ''));

        $default = $row['default'] ?? null;
        if (is_array($default)) {
            // elementSelect posts an array of ids — take the first non-empty.
            $filtered = array_values(array_filter($default, fn($v) => $v !== '' && $v !== null));
            $default  = (string)($filtered[0] ?? '');
        }
        $default = is_string($default) ? trim($default) : '';

        $options          = $this->decodeOptionsBlob($row['options'] ?? null);
        $subFields        = $this->subFields($row['fields'] ?? null);
        $nativeSubFields  = $this->subFields($row['nativeFields'] ?? null);

        $hasAnything = $node !== '' || $default !== '' || !empty($options)
            || !empty($subFields) || !empty($nativeSubFields);
        if (!$hasAnything) {
            return null;
        }

        $entry = [];
        if (!empty($row['type'])) {
            $entry['type'] = trim((string)$row['type']);
        }
        if ($node !== '') {
            $entry['node'] = $node;
        }
        if ($default !== '') {
            $entry['default'] = $default;
        }
        if (!empty($options)) {
            $entry['options'] = $options;
        }
        if (!empty($subFields)) {
            $entry['fields'] = $subFields;
        }
        if (!empty($nativeSubFields)) {
            $entry['nativeFields'] = $nativeSubFields;
        }
        return $entry;
    }

    /**
     * The Vue MappingExtras component posts options as a single JSON string;
     * legacy callers may post it as a normal array. Accept both.
     */
    private function decodeOptionsBlob(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalise either a JSON blob (Vue) or a nested array (legacy/Twig) of
     * sub-mapping rows into the recursive Project Config shape.
     */
    private function subFields(mixed $raw): array
    {
        $rows = $this->decodeOptionsBlob($raw);
        if (empty($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $subHandle => $subRow) {
            if (!is_string($subHandle) || !is_array($subRow)) {
                continue;
            }
            $normalised = $this->mappingRow($subRow);
            if ($normalised !== null) {
                $out[$subHandle] = $normalised;
            }
        }
        return $out;
    }

    private function emptyToNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string)$value;
    }

    /**
     * Form fields can post as `''` when empty (table widgets sometimes do
     * this for an empty row count). Coerce anything that isn't already an
     * array to `[]` so the typed normalisers below can rely on the input.
     */
    private function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
