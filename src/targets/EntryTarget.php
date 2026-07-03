<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeInterface;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\support\EntryTypeResolver;

/**
 * Default target for craft\elements\Entry.
 *
 * Recognized elementCriteria keys:
 *   section: handle of the section (required for new entries)
 *   type:    handle of the entry type (required for new entries)
 *   author:  id or username of the default author (optional)
 */
class EntryTarget extends AbstractElementTarget
{
    public static function elementType(): string
    {
        return Entry::class;
    }

    public function claimsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof Entry)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $sectionHandle = $link->elementCriteria['section'] ?? null;

        if ($sectionHandle && $element->getSection()?->handle !== $sectionHandle) {
            return false;
        }

        $typeHandle = $link->elementCriteria['type'] ?? null;

        if ($typeHandle && $element->getType()?->handle !== $typeHandle) {
            return false;
        }

        $matchAttr = $link->matchAttribute();

        if (! $matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
    }

    /**
     * No native author short-circuit anymore — {@see parseAuthor()} resolves
     * the value (node, falling back to `default`) through the configured
     * match strategy and sets `authorIds` itself.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return false;
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Entry
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Entry::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        if (isset($link->elementCriteria['section'])) {
            $query->section($link->elementCriteria['section']);
        }

        if (isset($link->elementCriteria['type'])) {
            $query->type($link->elementCriteria['type']);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query->one();
    }

    /**
     * Candidate set for the missing-elements sweep: every entry this link owns
     * (same section/type scoping as {@see findByMatchValue()}), minus the ids
     * the run just saw. Returns null only when the link has no match attribute
     * at all — such a link can't sync, so there's nothing to sweep.
     *
     * Feed-authoritative scope: the link's element criteria (section/type) ARE
     * the ownership boundary — every entry inside that scope is managed by this
     * link. So an entry with an EMPTY match value is a sweep candidate too: no
     * feed item can ever match it (matching keys on the match value), so it is
     * permanently "missing from the feed" and belongs in the candidate set.
     * (The earlier `:notempty:` refinement — added on the theory that a blank
     * match value meant "not ours" — is dropped: the criteria scope already
     * answers "is this ours", and blank-keyed orphans are exactly what the
     * sweep is meant to clear.)
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = Entry::find();

        if (isset($link->elementCriteria['section'])) {
            $query->section($link->elementCriteria['section']);
        }

        if (isset($link->elementCriteria['type'])) {
            $query->type($link->elementCriteria['type']);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        // Exclude the ids the run just touched — Craft's 'not' prefix syntax.
        // An empty seen-set means no items matched: leave the id param off so
        // the whole owned set is a candidate (the policy's status filter and
        // the unattributed-errors guard still gate what actually gets swept).
        if ($seenIds !== []) {
            $query->id(array_merge(['not'], $seenIds));
        }

        return $query;
    }

    public function buildNew(Link $link, ?int $siteId = null): Entry
    {
        [$section, $entryType] = (new EntryTypeResolver())->resolve($link);

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;

        if ($siteId) {
            $entry->siteId = $siteId;
        }

        return $entry;
    }

    /**
     * Adds slug/title on top of the base `id` — but only when the link's
     * resolved entry type actually enables them (title fields can be
     * generated via titleFormat, slug fields hidden per type). Unresolved
     * criteria fall back to id-only.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        $attributes = parent::matchableNativeAttributes($link);

        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return $attributes;
        }
        [, $entryType] = $resolved;

        if (Compat::entryTypeShowsSlugField($entryType)) {
            $attributes[] = ['value' => 'slug', 'label' => Craft::t('influx', 'Slug (slug)')];
        }

        if ($entryType->hasTitleField) {
            // The title's label is user-editable in the entry type's field
            // layout — surface what the editor actually sees. label()
            // handles the custom value (site-translated) and the default.
            $titleElement = $entryType->getFieldLayout()?->getFirstElementByType(
                EntryTitleField::class,
            );
            $titleLabel = $titleElement?->label() ?: Craft::t('app', 'Title');
            $attributes[] = ['value' => 'title', 'label' => "{$titleLabel} (title)"];
        }

        return $attributes;
    }

    public function getMappableFields(Link $link): array
    {
        $fields = $this->nativeFieldDefinitions();

        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return $fields;
        }
        [, $entryType] = $resolved;

        $layout = $entryType->getFieldLayout();

        if (! $layout) {
            return $fields;
        }

        // Walk the field-layout tabs so custom fields keep the same grouping
        // they have in Craft's own entry editor. CustomField elements have a
        // `field` property; tabs without a name fall back to a generic label.
        $fallbackTab = Craft::t('influx', 'Content');

        foreach ($layout->getTabs() as $tab) {
            $tabName = $tab->name ?: $fallbackTab;

            foreach ($tab->getElements() as $element) {
                if (! ($element instanceof CustomField)) {
                    continue;
                }
                $field = $element->getField();

                if (! $field) {
                    continue;
                }
                $fields[] = [
                    'handle'      => $field->handle,
                    'name'        => $field->name,
                    'native'      => false,
                    'group'       => $tabName,
                    'defaultType' => 'text',
                    'fieldClass'  => $field::class,
                    'fieldMeta'   => Influx::getInstance()->fields->metaFor($field),
                ];
            }
        }

        return $fields;
    }

    // -- native attribute parsers (dispatched by handle) ---------------------

    /**
     * Feed titles routinely overflow Craft's 255-char title column —
     * truncate safely instead of letting the save fail. Mirrors feed-me's
     * title hygiene.
     */
    protected function parseTitle(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        // An active mapping that's now empty clears the title — the feed is
        // authoritative. Saves run with validation off, so an empty title
        // persists rather than failing (mirrors Feed Me's essentials scenario).
        $new = $value === null ? null : StringHelper::safeTruncate((string) $value, 255);
        $changed = (string) ($element->title ?? '') !== (string) ($new ?? '');
        $element->title = $new;

        return $changed;
    }

    /**
     * Slugs straight from a feed are rarely slug-safe — normalize the same
     * way Craft does when auto-generating (respects limitAutoSlugsToAscii
     * and allowUppercaseInSlug).
     */
    protected function parseSlug(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        // Empty clears the slug — Craft regenerates it from the title on save.
        $new = $value === null ? null : ElementHelper::normalizeSlug((string) $value);
        $changed = (string) ($element->slug ?? '') !== (string) ($new ?? '');
        $element->slug = $new;

        return $changed;
    }

    /**
     * Resolve the per-item author through the same match strategy the
     * relational Users field uses (id / username / email / custom field),
     * then assign as `authorIds`. Falls back to the mapping's `default` (a
     * user-id picked via elementSelect) when no node value is present.
     */
    protected function parseAuthor(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Entry $element */
        // Compare current author id(s) against the INTENDED id — computed here,
        // not read back off the element after setting it (reading the resolved
        // author relation back on an unsaved element is unreliable). An empty
        // value, or one that matches no user, clears the author.
        $before = Compat::entryAuthorIds($element);
        $newId = $this->resolveAuthorId($context, $item, $mapping);

        Compat::setEntryAuthor($element, $newId);

        return $before !== ($newId === null ? [] : [$newId]);
    }

    /**
     * Resolve the author user id for one item. A feed *node* value is matched
     * via the configured `match` strategy (id / username / email / field). The
     * mapping's `default` is a different thing: a user id picked in the CP via
     * the element selector, so it's matched by id regardless of `match` — the
     * strategy applies to feed values, not the picked default. (Matching the
     * picked default id through, say, the `email` strategy finds nobody and
     * wrongly clears the author.)
     */
    protected function resolveAuthorId(SyncContext $context, RemoteItem $item, FieldMapping $mapping): ?int
    {
        $nodeValue = $mapping->rawValue($item);

        if ($nodeValue !== null && $nodeValue !== '') {
            return $this->findUser($context, (string) $mapping->option('match', 'id'), $nodeValue)?->id;
        }

        if ($mapping->useDefault && $mapping->default !== null && $mapping->default !== '') {
            return $this->findUser($context, 'id', $mapping->default)?->id;
        }

        return null;
    }

    protected function parsePostDate(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignDate($element, 'postDate', $item, $mapping);
    }

    protected function parseExpiryDate(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignDate($element, 'expiryDate', $item, $mapping);
    }

    /**
     * Coerce the mapped value into the `enabled` flag. (`status` itself is
     * derived by Craft from enabled + postDate + expiryDate and can't be set
     * directly — that's why the native mappable is `enabled`, not `status`.)
     * Truthy spellings follow the Lightswitch field strategy.
     */
    protected function parseEnabled(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        // Empty clears to disabled — an empty boolean is false.
        $new = match (true) {
            $value === null => false,
            is_bool($value) => $value,
            default         => in_array(strtolower(trim((string) $value)), Lightswitch::TRUTHY_VALUES, true),
        };

        $changed = (bool) $element->enabled !== $new;
        $element->enabled = $new;

        return $changed;
    }

    protected function assignDate(ElementInterface $element, string $attr, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);
        $before = $element->{$attr};

        // Empty clears the date (the feed is authoritative).
        if ($value === null || $value === '') {
            $element->{$attr} = null;

            return $before !== null;
        }

        $parsed = $this->parseDateValue($value, $mapping);

        // A present-but-unparseable value is left as a no-op — malformed feed
        // data shouldn't wipe a valid stored date.
        if ($parsed === null) {
            return false;
        }

        $element->{$attr} = $parsed;

        return ! ($before instanceof DateTimeInterface) || $before->getTimestamp() !== $parsed->getTimestamp();
    }

    /**
     * Parse a resolved feed value into a DateTime, or null when it can't be
     * parsed. An explicit `format` option wins over the auto-detector — feeds
     * that ship ambiguous strings (e.g. `02/03/2024`) need to disambiguate
     * manually. `timestamp` is a UI sentinel for Unix seconds (translated to
     * the PHP `U` token here so the Vue side stays human-readable).
     */
    protected function parseDateValue(mixed $value, FieldMapping $mapping): ?DateTime
    {
        if ($value instanceof DateTimeInterface) {
            return $value instanceof DateTime ? $value : DateTime::createFromInterface($value);
        }

        $format = $mapping->option('format');

        if (is_string($format) && $format !== '') {
            $phpFormat = $format === 'timestamp' ? 'U' : $format;
            $parsed = DateTime::createFromFormat($phpFormat, (string) $value);

            return $parsed === false ? null : $parsed;
        }

        return DateTimeHelper::toDateTime($value) ?: null;
    }

    /**
     * Resolve a user by the given match strategy, memoized on the run's lookup
     * cache under the `author` scope. Feeds routinely repeat the same author
     * across many items, so caching collapses those to a single query. Users
     * are never created by the sync, so an author miss can't go stale within a
     * run — the cached null is always correct for that run.
     */
    protected function findUser(SyncContext $context, string $match, mixed $value): ?User
    {
        $element = $context->lookups->remember(User::class, $match, 'author', (string) $value, function() use ($match, $value) {
            $query = User::find()->status(null);
            match ($match) {
                'id'       => $query->id((int) $value),
                'username' => $query->username((string) $value),
                'email'    => $query->email((string) $value),
                default    => $query->$match($value),
            };

            return $query->one();
        });

        return $element instanceof User ? $element : null;
    }

    // -- mappable-field metadata ----------------------------------------------

    /**
     * The Entry-native mappable attributes — the fixed part of
     * {@see getMappableFields()}, independent of any section/type criteria.
     *
     * @return list<array>
     */
    protected function nativeFieldDefinitions(): array
    {
        $native = Craft::t('influx', 'Native');

        return [
            ['handle' => 'title', 'name' => Craft::t('app', 'Title'), 'native' => true, 'group' => $native, 'defaultType' => 'text'],
            ['handle' => 'slug',  'name' => Craft::t('app', 'Slug'),  'native' => true, 'group' => $native, 'defaultType' => 'text'],
            [
                'handle'      => 'enabled',
                'name'        => Craft::t('app', 'Enabled'),
                'native'      => true,
                'group'       => $native,
                'defaultType' => 'select',
                'options'     => [
                    'true'  => Craft::t('app', 'Enabled'),
                    'false' => Craft::t('app', 'Disabled'),
                ],
            ],
            [
                'handle'    => 'postDate', 'name' => Craft::t('app', 'Post Date'),
                'native'    => true, 'group' => $native, 'defaultType' => 'text',
                'fieldMeta' => $this->dateFieldMeta(),
            ],
            [
                'handle'    => 'expiryDate', 'name' => Craft::t('app', 'Expiry Date'),
                'native'    => true, 'group' => $native, 'defaultType' => 'text',
                'fieldMeta' => $this->dateFieldMeta(),
            ],
            [
                'handle'      => 'author',
                'name'        => Craft::t('app', 'Author'),
                'native'      => true,
                'group'       => $native,
                'defaultType' => 'element',
                'elementType' => User::class,
                'fieldMeta'   => [
                    'schema' => [
                        BuilderSchema::select('match', Craft::t('influx', 'Match by'), $this->authorMatchOptions(), [
                            'default' => 'id',
                        ]),
                    ],
                    'labels' => Field::commonExtrasLabels(),
                ],
            ],
        ];
    }

    /**
     * Shared meta for postDate/expiryDate so the date extras block reads its
     * preset format list from {@see \GlueAgency\Influx\fields\Date}, same as the
     * custom Date field strategy.
     *
     * @return array<string, mixed>
     */
    protected function dateFieldMeta(): array
    {
        return [
            'schema' => [
                BuilderSchema::select('format', Craft::t('influx', 'Date format'), Date::formatOptions(), [
                    'instructions' => Craft::t('influx', 'Used by DateTime::createFromFormat. "Unix timestamp" parses integer seconds; "Auto-detect" uses the Craft DateTimeHelper.'),
                    'default'      => '',
                ]),
            ],
            'labels' => Field::commonExtrasLabels(),
        ];
    }

    /**
     * Match-by options for the native author dropdown. Built statically (no
     * Craft field instance to introspect) — id/username/email cover the
     * native identifiers, then any custom fields on the global User layout
     * are surfaced so unique handles like an external `importId` can match.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    protected function authorMatchOptions(): array
    {
        $groups = [
            [
                'label'   => Craft::t('influx', 'User'),
                'kind'    => 'element',
                'options' => [
                    ['value' => 'id',       'label' => Craft::t('influx', 'ID (id)')],
                    ['value' => 'username', 'label' => Craft::t('influx', 'Username (username)')],
                    ['value' => 'email',    'label' => Craft::t('influx', 'Email (email)')],
                ],
            ],
        ];

        $layout = Craft::$app->getFields()->getLayoutByType(User::class);
        $customFields = [];

        if ($layout) {
            foreach ($layout->getCustomFields() as $customField) {
                $customFields[] = [
                    'value' => $customField->handle,
                    'label' => $customField->name . ' (' . $customField->handle . ')',
                ];
            }
        }

        if ($customFields) {
            $groups[] = ['label' => Craft::t('influx', 'Fields'), 'kind' => 'fields', 'options' => $customFields];
        }

        return $groups;
    }
}
