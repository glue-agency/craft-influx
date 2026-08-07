<?php

namespace GlueAgency\Influx\integrations\craftcms\feedme;

use Craft;
use craft\helpers\StringHelper;
use GlueAgency\Influx\enums\MatrixBlockSource;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\MappingCollection;
use GlueAgency\Influx\targets\EntryTarget;

/**
 * Converts a craftcms/feed-me feed (a row from the `feedme_feeds` table) into
 * an Influx {@see Link}.
 *
 * The conversion is best-effort by design: the two plugins overlap heavily —
 * Influx's mapping options (`match`, `create`, `group`, ...) deliberately
 * mirror Feed Me's — but not perfectly. Whatever can't be
 * carried over (parent entries, non-JSON feed types, ...) is reported as a
 * warning on the {@see FeedMeConversion} so the user can finish the link in
 * the builder instead of silently losing config. Matrix `blocks` mappings DO
 * carry over — each block type's `fields` recurse through the same conversion
 * ({@see convertMatrixBlocks()}).
 *
 * Feed Me / Influx vocabulary map:
 *
 *   feedUrl          → endpoint (or siteEndpoints[site] on multi-site feeds)
 *   primaryElement   → rootNode
 *   paginationNode   → paginatorNode
 *   duplicateHandle  → processing  (add→create, ...)
 *   fieldMapping     → mappings    (`/` node paths → Hash dot-paths)
 *   fieldUnique      → match       (first unique field wins; Influx is single-match)
 *   elementGroup     → elementCriteria (section/entryType ids → handles)
 *
 * One converter covers Feed Me 4 (Craft 3), 5 (Craft 4) and 6 (Craft 5)
 * deliberately — no per-version importer. The stored feed shape is identical
 * across all three majors (schema, elementGroup, duplicateHandle flags,
 * sentinel nodes, option keys all match); the divergences are the entry
 * author native handle (`authorId` through v5, `authorIds` since v6) and the
 * relation `options.match` value format (raw content-table column names
 * through v5 — see {@see normalizeMatchOption()} — bare handles since v6).
 * And because Feed Me never rewrites `fieldMapping` JSON on upgrade, a v6
 * install routinely still holds rows saved by v4/v5 — the vintage is a
 * property of the row, not of the installed version, so version detection
 * would key off the wrong thing anyway. All formats are simply accepted.
 *
 * Craft lookups (sections, entry types, sites) live in protected methods so
 * the unit suite — which runs without a Craft boot — can stub them out.
 */
class FeedMeConverter
{
    /** Feed Me's sentinel node for "don't import this field". */
    public const NODE_NO_IMPORT = 'noimport';

    /** Feed Me's sentinel node for "always write the default value". */
    public const NODE_USE_DEFAULT = 'usedefault';

    /**
     * Feed Me native attribute handles (for entries) that Influx knows under
     * a different name. `authorId` is what Feed Me ≤5 stored; v6 renamed it
     * to `authorIds` for Craft 5's multi-author support — rows of both
     * vintages coexist in upgraded installs, so both are accepted.
     */
    protected const NATIVE_HANDLE_MAP = [
        'authorId'  => 'author',
        'authorIds' => 'author',
    ];

    /**
     * Feed Me native attribute handles with no Influx counterpart. Mappings
     * (and unique flags) on these are dropped with a warning.
     * (`localeEnabled` has been UI-disabled in Feed Me for years but can
     * survive in rows saved by very old versions.)
     */
    protected const UNSUPPORTED_NATIVE_HANDLES = ['parent', 'id', 'localeEnabled'];

    /**
     * Feed Me date-format sentinels (stored, confusingly, under
     * `options.match`) → Influx `options.format` strings for
     * `DateTime::createFromFormat`. Lenient `n`/`j` tokens stand in for Feed
     * Me's forgiving regex parsing; `auto` maps to '' (Influx auto-detects
     * by default); `seconds` maps to Influx's `timestamp` sentinel.
     * `milliseconds` has no counterpart and is handled separately. Identical
     * across Feed Me 4/5/6.
     */
    protected const DATE_FORMAT_MAP = [
        'auto'          => '',
        'america'       => 'n/j/Y',
        'america-short' => 'n/j/y',
        'asia'          => 'Y/n/j',
        'asia-short'    => 'y/n/j',
        'world'         => 'j/n/Y',
        'world-short'   => 'j/n/y',
        'yyyymmdd'      => 'Ymd',
        'yymmdd'        => 'ymd',
        'yyyyddmm'      => 'Ydm',
        'yyddmm'        => 'ydm',
        'seconds'       => 'timestamp',
    ];

    /**
     * duplicateHandle value → Influx processing action. `disableForSite` is
     * handled separately (approximated to `disable` with a warning).
     */
    protected const PROCESSING_MAP = [
        'add'           => ProcessingAction::CREATE,
        'update'        => ProcessingAction::UPDATE,
        'disable'       => ProcessingAction::DISABLE,
        'delete'        => ProcessingAction::DELETE,
        'deleteForSite' => ProcessingAction::DELETE_FOR_SITE,
    ];

    /**
     * Warnings collected while converting the current feed.
     *
     * @var string[]
     */
    protected array $warnings = [];

    /**
     * Convert one `feedme_feeds` row. JSON columns (`elementGroup`,
     * `duplicateHandle`, `fieldMapping`, `fieldUnique`) may arrive either as
     * raw JSON strings (straight from the DB) or already decoded.
     */
    public function convert(array $feed): FeedMeConversion
    {
        $this->warnings = [];

        $link = new Link();
        $link->name = (string) ($feed['name'] ?? '');
        $link->handle = $this->deriveHandle($feed);
        $link->elementType = ltrim((string) ($feed['elementType'] ?? ''), '\\');
        $link->backup = ! empty($feed['backup']);

        $this->convertFeedType($feed);
        $this->convertEndpoint($feed, $link);

        $link->rootNode = $this->nodeToDotPath($feed['primaryElement'] ?? null);

        if ($link->rootNode !== null) {
            $this->warn("Feed Me locates the primary element '{$link->rootNode}' by name anywhere in the document; Influx needs the full dot-path from the response root. Verify the root node.");
        }
        $link->paginatorNode = $this->nodeToDotPath($feed['paginationNode'] ?? null);

        $link->elementCriteria = $this->convertElementCriteria($feed, $link->elementType);
        $link->processing = $this->convertProcessing($this->decode($feed['duplicateHandle'] ?? null));
        $link->mappings = $this->convertMappings($this->decode($feed['fieldMapping'] ?? null), true);
        $link->match = $this->convertMatch($this->decode($feed['fieldUnique'] ?? null), $link->mappings);

        if (! empty($feed['singleton'])) {
            $this->warn('Feed is a singleton; Influx has no singleton mode — the match attribute decides which element each item updates.');
        }

        if (empty($feed['setEmptyValues'])) {
            $this->warn('This feed had "Set empty values" off, but Influx always writes empty values through — a mapped field with no data in the feed is cleared on sync.');
        }

        return new FeedMeConversion($link, $this->warnings);
    }

    /**
     * Handle derived from the feed name, falling back to `feed{id}` when the
     * name yields nothing handle-safe. Uniqueness against existing links is
     * the caller's job — the converter sees one feed at a time.
     */
    protected function deriveHandle(array $feed): string
    {
        $handle = $this->handleFromName((string) ($feed['name'] ?? ''));

        if ($handle !== '') {
            return $handle;
        }

        return 'feed' . (string) ($feed['id'] ?? '');
    }

    /**
     * Influx only consumes JSON APIs — other feed types still convert (the
     * endpoint and mapping structure carry over) but won't run as-is.
     */
    protected function convertFeedType(array $feed): void
    {
        $feedType = (string) ($feed['feedType'] ?? '');

        if ($feedType !== '' && $feedType !== 'json') {
            $this->warn("Feed type is '{$feedType}' but Influx only consumes JSON APIs. The link will not sync until the endpoint returns JSON.");
        }
    }

    /**
     * Feed Me feeds import into one site; Influx models that as a per-site
     * endpoint. On multi-site installs the feed's target site keeps that
     * behavior via `siteEndpoints`; single-site installs get the plain
     * default endpoint.
     */
    protected function convertEndpoint(array $feed, Link $link): void
    {
        $feedUrl = (string) ($feed['feedUrl'] ?? '');
        $siteId = (int) ($feed['siteId'] ?? 0);

        if ($siteId && $this->isMultiSite()) {
            $siteHandle = $this->siteHandleById($siteId);

            if ($siteHandle !== null) {
                $link->siteEndpoints = [['site' => $siteHandle, 'endpoint' => $feedUrl]];
                $this->warn("Feed targeted site '{$siteHandle}'; converted to a site endpoint so the link only writes that site. Add more site endpoints if needed.");

                return;
            }
            $this->warn("Feed targeted site id {$siteId}, which no longer exists; using a default endpoint instead.");
        }

        $link->endpoint = $feedUrl !== '' ? $feedUrl : null;
    }

    /**
     * Feed Me's elementGroup stores ids per element type, e.g.
     * `{craft\elements\Entry: {section: 2, entryType: 4}}` — Influx criteria
     * use handles, under the keys {@see EntryTarget} declares. Only entries are
     * converted (the only built-in Influx target); other element types keep an
     * empty criteria with a warning.
     */
    protected function convertElementCriteria(array $feed, string $elementType): array
    {
        $group = $this->decode($feed['elementGroup'] ?? null);
        $settings = $group[$elementType] ?? $group['\\' . $elementType] ?? null;

        if ($elementType !== 'craft\elements\Entry') {
            if ($elementType !== '') {
                $this->warn("Element type '{$elementType}' has no built-in Influx target; element criteria were not converted.");
            }

            return [];
        }

        if (! is_array($settings)) {
            return [];
        }

        $criteria = [];

        $sectionId = (int) ($settings['section'] ?? 0);

        if ($sectionId) {
            $sectionHandle = $this->sectionHandleById($sectionId);

            if ($sectionHandle !== null) {
                $criteria[EntryTarget::CRITERIA_SECTION] = $sectionHandle;
            } else {
                $this->warn("Section id {$sectionId} no longer exists; set the section on the link manually.");
            }
        }

        $entryTypeId = (int) ($settings['entryType'] ?? 0);

        if ($entryTypeId) {
            $typeHandle = $this->entryTypeHandleById($entryTypeId);

            if ($typeHandle !== null) {
                $criteria[EntryTarget::CRITERIA_TYPE] = $typeHandle;
            } else {
                $this->warn("Entry type id {$entryTypeId} no longer exists; set the entry type on the link manually.");
            }
        }

        return $criteria;
    }

    /**
     * duplicateHandle → processing. Unknown flags warn instead of failing so
     * a feed from a newer/older Feed Me still converts.
     *
     * @param array $duplicateHandle e.g. ['add', 'update']
     * @return string[]
     */
    protected function convertProcessing(array $duplicateHandle): array
    {
        $processing = [];

        foreach ($duplicateHandle as $flag) {
            if (! is_string($flag)) {
                continue;
            }

            if (isset(self::PROCESSING_MAP[$flag])) {
                $processing[] = self::PROCESSING_MAP[$flag]->value;

                continue;
            }

            if ($flag === 'disableForSite') {
                $processing[] = ProcessingAction::DISABLE->value;
                $this->warn("'Disable missing elements for site' is not supported; approximated to 'disable'.");

                continue;
            }
            $this->warn("Unknown duplicate handling flag '{$flag}' was dropped.");
        }

        $processing = array_values(array_unique($processing));

        if (empty($processing)) {
            $this->warn("Feed had no duplicate handling flags; defaulted to 'create' + 'update'.");

            return ProcessingAction::defaults();
        }

        return $processing;
    }

    /**
     * fieldMapping → mappings. Node paths swap Feed Me's `/` separators for
     * Influx's Hash dot-paths; sentinel nodes translate to "skip" /
     * "useDefault"; sub-field maps recurse. Matrix `blocks` mappings convert
     * per block type via {@see convertMatrixBlocks()} (Influx's `blocks`
     * channel mirrors Feed Me's stored shape).
     *
     * No-ops drop silently: an unsupported native attribute only warns when the
     * feed actually mapped a value there, and a Matrix field whose block types
     * mapped nothing is skipped without a warning.
     *
     * The converted mappings are assembled as {@see FieldMapping}s and emitted
     * through {@see MappingCollection::toConfig()}, so the stored shape (and its
     * empty-slot rule) is owned by the value objects rather than restated here.
     *
     * @param array $fieldMapping decoded Feed Me fieldMapping
     * @param bool $topLevel whether these handles are element-level (native
     * attribute renames only apply there, not on related-element sub-fields)
     */
    protected function convertMappings(array $fieldMapping, bool $topLevel): array
    {
        $mappings = [];

        foreach ($fieldMapping as $handle => $info) {
            if (! is_array($info)) {
                continue;
            }

            if ($topLevel && ! empty($info['attribute'])) {
                if (in_array($handle, self::UNSUPPORTED_NATIVE_HANDLES, true)) {
                    if ($this->mapsAValue($info)) {
                        $this->warn("Native attribute mapping '{$handle}' has no Influx counterpart and was dropped.");
                    }

                    continue;
                }
                $handle = self::NATIVE_HANDLE_MAP[$handle] ?? $handle;
            }

            if (is_array($info['blocks'] ?? null)) {
                $blocks = $this->convertMatrixBlocks((string) $handle, $info['blocks']);

                if ($blocks !== []) {
                    $derived = $this->deriveBlockSource((string) $handle, $blocks);

                    $mappings[$handle] = FieldMapping::make(
                        (string) $handle,
                        node: $derived['node'],
                        options: $derived['options'],
                        blocks: $derived['blocks'],
                    );
                }

                continue;
            }

            $mapping = $this->convertMapping((string) $handle, $info);

            if ($mapping !== null) {
                $mappings[$handle] = $mapping;
            }
        }

        return MappingCollection::of($mappings)->toConfig();
    }

    /**
     * Convert a Feed Me Matrix `blocks` map into Influx's per-block-type
     * sub-mapping trees. Feed Me nests block-type mappings as
     * `blocks.<typeHandle>.fields.<childHandle>` — custom fields only; there
     * are no per-type native-attribute rows to carry. Each type's `fields`
     * recurse through {@see convertMappings()} (topLevel false), so node
     * separators, sentinels (no-import / use-default), defaults and warnings
     * all apply per child. A block type whose fields all resolve to no-ops is
     * omitted; any per-type key other than `fields` that actually carries
     * mappings is warned and dropped.
     *
     * @param array $blocks decoded Feed Me `blocks` map
     * @return array<string, array{fields: array}>
     */
    protected function convertMatrixBlocks(string $handle, array $blocks): array
    {
        $converted = [];

        foreach ($blocks as $typeHandle => $typeInfo) {
            if (! is_string($typeHandle) || ! is_array($typeInfo)) {
                continue;
            }

            $fields = is_array($typeInfo['fields'] ?? null)
                ? $this->convertMappings($typeInfo['fields'], false)
                : [];

            $this->warnUnsupportedBlockKeys($handle, $typeHandle, $typeInfo);

            if ($fields !== []) {
                $converted[$typeHandle] = FieldMapping::make($typeHandle, fields: $fields)->toConfig();
            }
        }

        return $converted;
    }

    /**
     * Pick the block source a converted Matrix field should read with, and
     * rewrite its child paths to suit.
     *
     * This is a FIDELITY fix, not an enhancement. Feed Me walks the feed rather
     * than the field config and sorts on the array index it finds in each node
     * path, so its blocks come out in the feed's own order, interleaved across
     * types. Influx's default grouped source emits one whole block type after
     * the other — so a converted link that carried `text, quote, text` produced
     * `text, text, quote`, silently. A Feed Me Matrix mapping is list-shaped by
     * construction (its paths only match anything because an index sits in
     * them), so a list source is the faithful reading.
     *
     * Feed Me stores paths index-free, which is what makes the shape readable:
     * every child of every type shares the list node as its first segment, and
     * under the wrapper shape each type's children then share ONE more segment
     * naming the type ({@see \GlueAgency\Influx\enums\MatrixBlockSource::LIST_BY_KEY}).
     * Flat children under a single configured type are LIST_SINGLE. Anything
     * else — types disagreeing on the list node, or several types whose
     * children sit flat and so can't be told apart — keeps the grouped source
     * and warns, because that ambiguity is one Feed Me itself resolves by
     * first-match-wins rather than correctly.
     *
     * @param array<string, array> $blocks converted per-type trees
     * @return array{node: ?string, options: array<string, mixed>, blocks: array<string, array>}
     */
    protected function deriveBlockSource(string $handle, array $blocks): array
    {
        $grouped = ['node' => null, 'options' => [], 'blocks' => $blocks];
        $segments = $this->blockChildSegments($blocks);

        if ($segments === []) {
            return $grouped;
        }

        $listNodes = [];

        foreach ($segments as $childSegments) {
            foreach ($childSegments as $path) {
                $listNodes[$path[0]] = true;
            }
        }

        if (count($listNodes) > 1) {
            $this->warn("Matrix field '{$handle}' maps block types from different feed nodes, so its blocks are grouped by type rather than kept in the feed's order; set a block source in the builder if the feed carries them as one list.");

            return $grouped;
        }

        $node = (string) array_key_first($listNodes);

        return $this->keyedBlockSource($handle, $blocks, $segments, $node)
            ?? $this->singleBlockSource($handle, $blocks, $segments, $node)
            ?? $grouped;
    }

    /**
     * Every mapped child's node path, split into segments, keyed by block type
     * and child handle. Children with no node (a "use default" row) carry no
     * path and so say nothing about the shape.
     *
     * @param array<string, array> $blocks
     * @return array<string, array<string, list<string>>>
     */
    protected function blockChildSegments(array $blocks): array
    {
        $segments = [];

        foreach ($blocks as $typeHandle => $typeConfig) {
            foreach ($typeConfig['fields'] ?? [] as $childHandle => $childConfig) {
                $node = $childConfig['node'] ?? null;

                if (is_string($node) && $node !== '') {
                    $segments[$typeHandle][$childHandle] = explode('.', $node);
                }
            }
        }

        return $segments;
    }

    /**
     * The wrapper reading: under the list node each type's children share one
     * more segment, and no two types share the same one. Null when the shape
     * isn't that, which hands the decision to the next reading.
     *
     * @param array<string, array> $blocks
     * @param array<string, array<string, list<string>>> $segments
     * @return array{node: string, options: array<string, mixed>, blocks: array<string, array>}|null
     */
    protected function keyedBlockSource(string $handle, array $blocks, array $segments, string $node): ?array
    {
        $keys = [];

        foreach ($segments as $typeHandle => $childSegments) {
            $typeKeys = [];

            foreach ($childSegments as $path) {
                if (count($path) < 3) {
                    return null;
                }

                $typeKeys[$path[1]] = true;
            }

            if (count($typeKeys) !== 1) {
                return null;
            }

            $keys[$typeHandle] = (string) array_key_first($typeKeys);
        }

        if (count(array_unique($keys)) !== count($keys)) {
            return null;
        }

        $options = ['blockSource' => MatrixBlockSource::LIST_BY_KEY->value];

        foreach ($keys as $typeHandle => $key) {
            if ($key !== $typeHandle) {
                $options['sourceKey_' . $typeHandle] = $key;
            }
        }

        return [
            'node'    => $node,
            'options' => $options,
            'blocks'  => $this->rebaseBlockChildren($blocks, $segments, 2),
        ];
    }

    /**
     * The flat reading: one configured block type whose children sit directly
     * under the list node. More than one type in that shape is the case Feed Me
     * resolves by first-match-wins — two types sharing a child handle are
     * attributed to whichever was configured first — so it warns and stays
     * grouped rather than inheriting the guess.
     *
     * @param array<string, array> $blocks
     * @param array<string, array<string, list<string>>> $segments
     * @return array{node: string, options: array<string, mixed>, blocks: array<string, array>}|null
     */
    protected function singleBlockSource(string $handle, array $blocks, array $segments, string $node): ?array
    {
        if (count($blocks) > 1) {
            $this->warn("Matrix field '{$handle}' maps several block types out of one flat list, which gives no way to tell an item's type apart; its blocks are grouped by type rather than kept in the feed's order.");

            return null;
        }

        foreach ($segments as $childSegments) {
            foreach ($childSegments as $path) {
                if (count($path) < 2) {
                    return null;
                }
            }
        }

        return [
            'node'    => $node,
            'options' => ['blockSource' => MatrixBlockSource::LIST_SINGLE->value],
            'blocks'  => $this->rebaseBlockChildren($blocks, $segments, 1),
        ];
    }

    /**
     * Drop the leading segments a list source now reads for itself, leaving each
     * child node relative to one list item — `content_blocks.text.image` becomes
     * `image`.
     *
     * @param array<string, array> $blocks
     * @param array<string, array<string, list<string>>> $segments
     * @return array<string, array>
     */
    protected function rebaseBlockChildren(array $blocks, array $segments, int $drop): array
    {
        foreach ($segments as $typeHandle => $childSegments) {
            foreach ($childSegments as $childHandle => $path) {
                $blocks[$typeHandle]['fields'][$childHandle]['node'] = implode('.', array_slice($path, $drop));
            }
        }

        return $blocks;
    }

    /**
     * Warn about per-block-type keys Influx can't carry. Only `fields` is
     * supported; a `fields`-only entry is silent. An `attributes` key (or any
     * other) that holds mappings is dropped with a warning so the user knows
     * to finish it in the builder.
     *
     * @param array $typeInfo one block type's decoded Feed Me config
     */
    protected function warnUnsupportedBlockKeys(string $handle, string $typeHandle, array $typeInfo): void
    {
        foreach ($typeInfo as $key => $value) {
            if ($key === 'fields' || ! is_array($value) || $value === []) {
                continue;
            }
            $this->warn("Matrix field '{$handle}' block type '{$typeHandle}' had unsupported '{$key}' mappings, which were dropped; re-map them on the block fields in the builder.");
        }
    }

    /**
     * Whether a Feed Me mapping entry actually carries a value to import —
     * either a real node, or "use default" with a non-empty default. Mirrors
     * the no-op guards in {@see convertMapping()} (which returns null for
     * "don't import", an unmapped node, or a default-only mapping with an
     * empty default); used to decide whether dropping an unsupported native
     * attribute is worth a warning.
     *
     * @param array $info a single decoded fieldMapping entry
     */
    protected function mapsAValue(array $info): bool
    {
        $node = $info['node'] ?? null;
        $node = is_string($node) && $node !== '' ? $node : null;

        if ($node === null || $node === self::NODE_NO_IMPORT) {
            return false;
        }

        if ($node === self::NODE_USE_DEFAULT) {
            return $this->normalizeDefault($info['default'] ?? null) !== null;
        }

        return true;
    }

    /**
     * Convert one fieldMapping entry to a {@see FieldMapping}, or null when the
     * entry carries nothing to import ("don't import", or a default-only mapping
     * with an empty default). A related element's sub-field map recurses through
     * {@see convertMappings()} into Influx's `fields` channel.
     *
     * Which slots survive into stored config is the value object's call
     * ({@see FieldMapping::toConfig()}); this only decides what the Feed Me row
     * translates to.
     */
    protected function convertMapping(string $handle, array $info): ?FieldMapping
    {
        $node = $info['node'] ?? null;
        $node = is_string($node) && $node !== '' ? $node : null;
        $default = $this->normalizeDefault($info['default'] ?? null);

        if ($node === self::NODE_NO_IMPORT || $node === null) {
            return null;
        }

        $useDefault = $node === self::NODE_USE_DEFAULT;

        if ($useDefault && $default === null) {
            return null;
        }

        $options = is_array($info['options'] ?? null) ? $this->cleanOptions($info['options']) : [];

        if (ltrim((string) ($info['field'] ?? ''), '\\') === 'craft\fields\Assets') {
            $options = $this->translateAssetOptions($handle, $options);
        } else {
            $options = $this->translateDateFormat($handle, $options);
            $options = $this->normalizeMatchOption($handle, $options);
            $options = $this->translateCreateGroup($handle, $options);
        }

        return FieldMapping::make(
            handle: $handle,
            node: $useDefault ? null : $this->nodeToDotPath($node),
            default: $default,
            useDefault: $useDefault,
            options: $options,
            fields: is_array($info['fields'] ?? null) ? $this->convertMappings($info['fields'], false) : [],
        );
    }

    /**
     * fieldUnique → match. Feed Me allows several unique fields; Influx
     * matches on exactly one attribute, which also must have a node-mapped
     * mapping. First convertible unique wins.
     *
     * @param array $fieldUnique e.g. ['title' => 1, 'myField' => '']
     * @param array $mappings the already-converted Influx mappings
     */
    protected function convertMatch(array $fieldUnique, array $mappings): array
    {
        $uniques = [];

        foreach ($fieldUnique as $handle => $flag) {
            if (empty($flag)) {
                continue;
            }

            if (in_array($handle, self::UNSUPPORTED_NATIVE_HANDLES, true)) {
                $this->warn("Unique identifier '{$handle}' cannot be matched by Influx; pick a different match attribute in the builder.");

                continue;
            }
            $uniques[] = self::NATIVE_HANDLE_MAP[$handle] ?? $handle;
        }

        if (empty($uniques)) {
            $this->warn('No usable unique identifier was found; set the match attribute in the builder.');

            return [];
        }

        $attribute = array_shift($uniques);

        if ($uniques) {
            $this->warn('Influx matches on a single attribute; using \'' . $attribute . '\' and ignoring: ' . implode(', ', $uniques) . '.');
        }

        if (empty($mappings[$attribute]['node'])) {
            $this->warn("Match attribute '{$attribute}' has no node-mapped mapping; the link won't validate until one is configured.");
        }

        return ['attribute' => $attribute];
    }

    /**
     * Feed Me node paths use `/` separators (numeric segments are real array
     * indexes); Influx reads Hash dot-paths. Empty stays null.
     */
    protected function nodeToDotPath(mixed $node): ?string
    {
        if (! is_string($node) || trim($node) === '') {
            return null;
        }

        return strtr(trim($node, '/'), '/', '.');
    }

    /**
     * Feed Me stores element-select defaults as id lists (`['12']`) even for
     * single selections — unwrap those so Influx's scalar-minded resolvers
     * (author match, relation match) get a usable value. Empty defaults
     * normalize to null.
     */
    protected function normalizeDefault(mixed $default): mixed
    {
        if (is_array($default) && array_is_list($default) && count($default) === 1) {
            $default = $default[0];
        }

        if ($default === null || $default === '' || $default === []) {
            return null;
        }

        return $default;
    }

    /**
     * Feed Me overloads `options.match` on date mappings to carry a
     * formatting sentinel ('america', 'world', 'seconds', ...) — the same
     * key relation mappings use for the lookup attribute. The sentinels
     * never collide with plausible match handles, so seeing one means "this
     * is a date mapping": translate it to Influx's `options.format` and
     * drop the foreign key. Non-sentinel matches pass through untouched.
     */
    protected function translateDateFormat(string $handle, array $options): array
    {
        $match = $options['match'] ?? null;

        if (! is_string($match)) {
            return $options;
        }

        if ($match === 'milliseconds') {
            unset($options['match']);
            $this->warn("Date mapping '{$handle}' parsed millisecond timestamps, which Influx does not support; the value will go through auto-detection instead.");

            return $options;
        }

        if (! array_key_exists($match, self::DATE_FORMAT_MAP)) {
            return $options;
        }

        unset($options['match']);
        $format = self::DATE_FORMAT_MAP[$match];

        if ($format !== '') {
            $options['format'] = $format;
            $this->warn("Date format '{$match}' on '{$handle}' was approximated to '{$format}'; verify it against the feed's date strings.");
        }

        return $options;
    }

    /**
     * Feed Me asset mappings speak a different option vocabulary than
     * Influx's Assets strategy: `match` ('filename'|'id', default
     * 'filename') instead of `mode` ('id'|'url'), plus `conflict: 'create'`
     * ("keep both") and `filenameNode`, which Influx dropped/never had.
     * Without this translation a migrated mapping never enters the
     * URL-lookup/upload branch — and the builder never shows the upload
     * toggle, because its visibility keys off `mode === 'url'`.
     *
     * `upload` itself carries over untouched (same key, same meaning).
     * Influx only honors it in url mode; Feed Me likewise treated uploads
     * as URL-data, even when matching by id, so upload forces url mode.
     *
     * Matching by id without uploads writes no `mode` at all — Influx's
     * default mode is already 'id'.
     */
    protected function translateAssetOptions(string $handle, array $options): array
    {
        $match = $options['match'] ?? 'filename';
        unset($options['match']);

        $upload = ! empty($options['upload']);

        if ($match !== 'id' || $upload) {
            $options['mode'] = 'url';

            if ($match === 'id') {
                $this->warn("Asset mapping '{$handle}' matched by id but had uploads enabled; converted to URL mode, which expects the feed to carry asset URLs.");
            }
        }

        if (($options['conflict'] ?? null) === 'create') {
            unset($options['conflict']);
            $this->warn("Asset conflict mode 'Keep both' on '{$handle}' is not supported; existing assets will be reused instead.");
        }

        if (! empty($options['filenameNode'])) {
            $this->warn("Asset option 'filenameNode' on '{$handle}' is not supported; Influx derives filenames from the URL.");
        }
        unset($options['filenameNode']);

        return $options;
    }

    /**
     * Feed Me 3.0.0-beta.26 through 5.x stored relation `options.match`
     * values for custom fields as raw content-table column names — it fed
     * them straight into a SQL `where` on the lookup query. That means
     * `field_<handle>`, and for fields created on Craft 3.7+ (which assigns
     * every new field a random column suffix) `field_<handle>_<suffix>`;
     * the native id match was stored as `elements.id`. Feed Me 6 (Craft 5,
     * no content table) switched to bare handles.
     *
     * Influx matches through element query params and setFieldValue(), which
     * take bare handles on every Craft version — so the column dressing is
     * translated away here. Handles are verified against the install's real
     * fields so a field genuinely named `field_*` survives, and the
     * column-suffix strip only happens when it lands on an existing field.
     */
    protected function normalizeMatchOption(string $handle, array $options): array
    {
        $match = $options['match'] ?? null;

        if (! is_string($match) || $match === '') {
            return $options;
        }

        if ($match === 'elements.id') {
            $options['match'] = 'id';

            return $options;
        }

        if (! str_starts_with($match, 'field_') || $this->fieldExistsByHandle($match)) {
            return $options;
        }

        $bare = substr($match, strlen('field_'));

        if (! $this->fieldExistsByHandle($bare)) {
            $stripped = preg_replace('/_[a-z0-9]{8}$/i', '', $bare);

            if ($stripped !== $bare && $this->fieldExistsByHandle($stripped)) {
                $bare = $stripped;
            } else {
                $this->warn("Match option '{$match}' on '{$handle}' was converted to '{$bare}', but no field with that handle exists; verify the Match by setting in the builder.");
            }
        }

        $options['match'] = $bare;

        return $options;
    }

    /**
     * Feed Me's "create entries if they do not exist" target —
     * `options.group.{sectionId,typeId}` — carries raw DB ids. Those are
     * environment-specific (sections and entry types are identified by
     * UID/handle in Project Config, ids differ per install), so the ids
     * are swapped for handles (`group.{section,type}`) here, while the
     * migration still runs in the environment the ids belong to.
     * {@see \GlueAgency\Influx\fields\Entries::createTarget()} resolves the
     * handles back to ids at sync time.
     */
    protected function translateCreateGroup(string $handle, array $options): array
    {
        $group = $options['group'] ?? null;

        if (! is_array($group)) {
            return $options;
        }

        $sectionId = (int) ($group['sectionId'] ?? 0);

        if ($sectionId) {
            unset($group['sectionId']);
            $sectionHandle = $this->sectionHandleById($sectionId);

            if ($sectionHandle !== null) {
                $group['section'] = $sectionHandle;
            } else {
                $this->warn("Create-target section id {$sectionId} on '{$handle}' no longer exists; pick a section in the builder.");
            }
        }

        $typeId = (int) ($group['typeId'] ?? 0);

        if ($typeId) {
            unset($group['typeId']);
            $typeHandle = $this->entryTypeHandleById($typeId);

            if ($typeHandle !== null) {
                $group['type'] = $typeHandle;
            } else {
                $this->warn("Create-target entry type id {$typeId} on '{$handle}' no longer exists; the section's first entry type will be used.");
            }
        }

        if (empty($group)) {
            unset($options['group']);
        } else {
            $options['group'] = $group;
        }

        return $options;
    }

    /**
     * Feed Me writes empty strings for unchecked options (`create: ''`);
     * dropping those keeps the Project Config payload clean. Option keys
     * themselves pass through untouched — Influx's option vocabulary
     * (`match`, `create`, `group.sectionId`, ...) intentionally mirrors
     * Feed Me's, and unknown keys are ignored at sync time.
     */
    protected function cleanOptions(array $options): array
    {
        $clean = [];

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $value = $this->cleanOptions($value);
            }

            if ($value === '' || $value === null || $value === []) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Decode a column that may be a JSON string (raw DB row) or already an
     * array. Anything else is "no data".
     */
    protected function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * One of the stubbable Craft lookups despite appearances:
     * `StringHelper::toHandle()` reads `Craft::$app->language`.
     */
    protected function handleFromName(string $name): string
    {
        return StringHelper::toHandle($name);
    }

    protected function isMultiSite(): bool
    {
        return Craft::$app->getIsMultiSite();
    }

    protected function siteHandleById(int $id): ?string
    {
        return Craft::$app->getSites()->getSiteById($id)?->handle;
    }

    protected function sectionHandleById(int $id): ?string
    {
        return Compat::getSectionById($id)?->handle;
    }

    protected function entryTypeHandleById(int $id): ?string
    {
        return Compat::getEntryTypeById($id)?->handle;
    }

    protected function fieldExistsByHandle(string $handle): bool
    {
        return Craft::$app->getFields()->getFieldByHandle($handle) !== null;
    }
}
