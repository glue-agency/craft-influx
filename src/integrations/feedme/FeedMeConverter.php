<?php

namespace GlueAgency\Influx\integrations\feedme;

use Craft;
use craft\helpers\StringHelper;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\Link;

/**
 * Converts a craftcms/feed-me feed (a row from the `feedme_feeds` table) into
 * an Influx {@see Link}.
 *
 * The conversion is best-effort by design: the two plugins overlap heavily —
 * Influx's mapping options (`match`, `create`, `group.sectionId`, ...)
 * deliberately mirror Feed Me's — but not perfectly. Whatever can't be
 * carried over (Matrix block mappings, parent entries, non-JSON feed types,
 * ...) is reported as a warning on the {@see FeedMeConversion} so the user
 * can finish the link in the builder instead of silently losing config.
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
 * sentinel nodes, option keys all match); the only divergence is the entry
 * author native handle (`authorId` through v5, `authorIds` since v6). And
 * because Feed Me never rewrites `fieldMapping` JSON on upgrade, a v6
 * install routinely still holds rows saved by v4/v5 — the vintage is a
 * property of the row, not of the installed version, so version detection
 * would key off the wrong thing anyway. Both handles are simply accepted.
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
        'enabled'   => 'status',
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
     * duplicateHandle value → Influx processing flag. `disableForSite` is
     * handled separately (approximated to `disable` with a warning).
     */
    protected const PROCESSING_MAP = [
        'add'           => Link::PROCESSING_CREATE,
        'update'        => Link::PROCESSING_UPDATE,
        'disable'       => Link::PROCESSING_DISABLE,
        'delete'        => Link::PROCESSING_DELETE,
        'deleteForSite' => Link::PROCESSING_DELETE_FOR_SITE,
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
        $link->name = (string)($feed['name'] ?? '');
        $link->handle = $this->deriveHandle($feed);
        $link->elementType = ltrim((string)($feed['elementType'] ?? ''), '\\');
        $link->backup = !empty($feed['backup']);

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

        if (!empty($feed['singleton'])) {
            $this->warn('Feed is a singleton; Influx has no singleton mode — the match attribute decides which element each item updates.');
        }
        if (!empty($feed['setEmptyValues'])) {
            $this->warn('"Set empty values" is not supported; Influx leaves fields untouched when the feed has no data for them.');
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
        $handle = $this->handleFromName((string)($feed['name'] ?? ''));
        if ($handle !== '') {
            return $handle;
        }
        return 'feed' . (string)($feed['id'] ?? '');
    }

    /**
     * Influx only consumes JSON APIs — other feed types still convert (the
     * endpoint and mapping structure carry over) but won't run as-is.
     */
    protected function convertFeedType(array $feed): void
    {
        $feedType = (string)($feed['feedType'] ?? '');
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
        $feedUrl = (string)($feed['feedUrl'] ?? '');
        $siteId = (int)($feed['siteId'] ?? 0);

        if ($siteId && $this->isMultiSite()) {
            $siteHandle = $this->siteHandleById($siteId);
            if ($siteHandle !== null) {
                $link->siteEndpoints = [$siteHandle => $feedUrl];
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
     * use handles. Only entries are converted (the only built-in Influx
     * target); other element types keep an empty criteria with a warning.
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

        if (!is_array($settings)) {
            return [];
        }

        $criteria = [];

        $sectionId = (int)($settings['section'] ?? 0);
        if ($sectionId) {
            $sectionHandle = $this->sectionHandleById($sectionId);
            if ($sectionHandle !== null) {
                $criteria['section'] = $sectionHandle;
            } else {
                $this->warn("Section id {$sectionId} no longer exists; set the section on the link manually.");
            }
        }

        $entryTypeId = (int)($settings['entryType'] ?? 0);
        if ($entryTypeId) {
            $typeHandle = $this->entryTypeHandleById($entryTypeId);
            if ($typeHandle !== null) {
                $criteria['type'] = $typeHandle;
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
            if (!is_string($flag)) {
                continue;
            }
            if (isset(self::PROCESSING_MAP[$flag])) {
                $processing[] = self::PROCESSING_MAP[$flag];
                continue;
            }
            if ($flag === 'disableForSite') {
                $processing[] = Link::PROCESSING_DISABLE;
                $this->warn("'Disable missing elements for site' is not supported; approximated to 'disable'.");
                continue;
            }
            $this->warn("Unknown duplicate handling flag '{$flag}' was dropped.");
        }

        $processing = array_values(array_unique($processing));

        if (empty($processing)) {
            $this->warn("Feed had no duplicate handling flags; defaulted to 'create' + 'update'.");
            return [Link::PROCESSING_CREATE, Link::PROCESSING_UPDATE];
        }

        return $processing;
    }

    /**
     * fieldMapping → mappings. Node paths swap Feed Me's `/` separators for
     * Influx's Hash dot-paths; sentinel nodes translate to "skip" /
     * "useDefault"; sub-field maps recurse. Matrix `blocks` mappings are
     * dropped — Influx doesn't support block-shaped mapping yet.
     *
     * @param array $fieldMapping decoded Feed Me fieldMapping
     * @param bool $topLevel whether these handles are element-level (native
     * attribute renames only apply there, not on related-element sub-fields)
     */
    protected function convertMappings(array $fieldMapping, bool $topLevel): array
    {
        $mappings = [];

        foreach ($fieldMapping as $handle => $info) {
            if (!is_array($info)) {
                continue;
            }

            if ($topLevel && !empty($info['attribute'])) {
                if (in_array($handle, self::UNSUPPORTED_NATIVE_HANDLES, true)) {
                    $this->warn("Native attribute mapping '{$handle}' has no Influx counterpart and was dropped.");
                    continue;
                }
                $handle = self::NATIVE_HANDLE_MAP[$handle] ?? $handle;
            }

            if (isset($info['blocks'])) {
                $this->warn("Matrix field '{$handle}' was dropped — Influx does not support Matrix block mapping yet.");
                continue;
            }

            $mapping = $this->convertMapping((string)$handle, $info);
            if ($mapping !== null) {
                $mappings[$handle] = $mapping;
            }
        }

        return $mappings;
    }

    /**
     * Convert one fieldMapping entry to Influx's mapping config shape, or
     * null when the entry carries nothing to import ("don't import", or a
     * default-only mapping with an empty default).
     */
    protected function convertMapping(string $handle, array $info): ?array
    {
        $node = $info['node'] ?? null;
        $node = is_string($node) && $node !== '' ? $node : null;
        $default = $this->normalizeDefault($info['default'] ?? null);

        $mapping = [];

        if ($node === self::NODE_NO_IMPORT || $node === null) {
            return null;
        }

        if ($node === self::NODE_USE_DEFAULT) {
            if ($default === null) {
                return null;
            }
            $mapping['useDefault'] = true;
        } else {
            $mapping['node'] = $this->nodeToDotPath($node);
        }

        if ($default !== null) {
            $mapping['default'] = $default;
        }

        $options = is_array($info['options'] ?? null) ? $this->cleanOptions($info['options']) : [];
        $options = $this->translateDateFormat($handle, $options);
        if (!empty($options)) {
            $mapping['options'] = $options;
        }

        // Related-element sub-fields: same conversion, one level down. Feed
        // Me only maps custom fields there, which is Influx's `fields` key.
        if (is_array($info['fields'] ?? null)) {
            $subMappings = $this->convertMappings($info['fields'], false);
            if (!empty($subMappings)) {
                $mapping['fields'] = $subMappings;
            }
        }

        return $mapping;
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
        if (!is_string($node) || trim($node) === '') {
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
        if (!is_string($match)) {
            return $options;
        }

        if ($match === 'milliseconds') {
            unset($options['match']);
            $this->warn("Date mapping '{$handle}' parsed millisecond timestamps, which Influx does not support; the value will go through auto-detection instead.");
            return $options;
        }

        if (!array_key_exists($match, self::DATE_FORMAT_MAP)) {
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

    // Craft lookups — isolated so the no-boot unit suite can stub them.
    // (StringHelper::toHandle transliterates via Craft::$app->language, so it
    // counts as one.)

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
}
