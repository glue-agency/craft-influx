<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use DateTimeInterface;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\support\EntryTypeResolver;

/**
 * Default target for craft\elements\Entry.
 *
 * Recognized elementCriteria keys — {@see CRITERIA_SECTION} (handle of the
 * section, required for new entries) and {@see CRITERIA_TYPE} (handle of the
 * entry type, required for new entries). This target OWNS those two key names:
 * every reader goes through {@see Link::criterion()} with one of the constants
 * below, so the literals live here and nowhere else.
 */
class EntryTarget extends AbstractElementTarget
{
    public const CRITERIA_SECTION = 'section';
    public const CRITERIA_TYPE = 'type';

    public static function elementType(): string
    {
        return Entry::class;
    }

    /**
     * Entries are scoped by section and entry type — the two dropdowns the
     * builder's General tab renders for this element type.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_SECTION, self::CRITERIA_TYPE];
    }

    /**
     * Section, then the entry types OF that section — the one criteria surface
     * with a cascade, and the case `dependsOn` / `optionsBy` exist for. Craft 5
     * shares entry types across sections, so a flat list would offer types the
     * picked section doesn't have; the per-section lists are shipped up front
     * because the whole project-config view is a handful of handles, and a fetch
     * per section change would be a round-trip for data the page already had.
     *
     * @return list<array>
     */
    public static function criteriaSchema(): array
    {
        $sections = [self::criteriaPlaceholder()];
        $typesBySection = [];

        foreach (Compat::getAllSections() as $section) {
            $sections[] = ['value' => $section->handle, 'label' => $section->name];

            $types = [self::criteriaPlaceholder()];

            foreach ($section->getEntryTypes() as $entryType) {
                $types[] = ['value' => $entryType->handle, 'label' => $entryType->name];
            }

            $typesBySection[$section->handle] = $types;
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_SECTION,
                'label'   => Craft::t('app', 'Section'),
                'options' => $sections,
            ])
            ->select([
                'handle'    => self::CRITERIA_TYPE,
                'label'     => Craft::t('app', 'Entry Type'),
                'dependsOn' => self::CRITERIA_SECTION,
                'optionsBy' => $typesBySection,
            ])
            ->toArray();
    }

    /**
     * "Movies / Feature" — the section, then the entry type when one is pinned.
     * Falls back to the stored handle when a section or type has since been
     * removed, so the overview never goes blank on config drift.
     */
    public function criteriaLabel(Link $link): ?string
    {
        $sectionHandle = $link->criterion(self::CRITERIA_SECTION);

        if (! $sectionHandle) {
            return null;
        }

        $section = Compat::getSectionByHandle($sectionHandle);
        $parts = [$section?->name ?? $sectionHandle];

        $typeHandle = $link->criterion(self::CRITERIA_TYPE);

        if ($typeHandle) {
            $typeName = null;

            foreach ($section?->getEntryTypes() ?? [] as $entryType) {
                if ($entryType->handle === $typeHandle) {
                    $typeName = $entryType->name;

                    break;
                }
            }

            $parts[] = $typeName ?? $typeHandle;
        }

        return implode(' / ', $parts);
    }

    /**
     * Structural targeting for entries: the element is an Entry the link
     * handles, inside the link's configured section and entry-type scope (each
     * criterion only bites when set). Purely about "is this entry in scope" —
     * it does NOT look at the match value, so an in-scope entry with no match
     * value still targets (that gap is what {@see claimsElement()} adds).
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof Entry)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $sectionHandle = $link->criterion(self::CRITERIA_SECTION);

        if ($sectionHandle !== null && $element->getSection()?->handle !== $sectionHandle) {
            return false;
        }

        $typeHandle = $link->criterion(self::CRITERIA_TYPE);

        if ($typeHandle !== null && $element->getType()?->handle !== $typeHandle) {
            return false;
        }

        return true;
    }

    /**
     * Entries partition by section × entry type, so a link's claim is the set of
     * `"{section} {entryType}"` cells its criteria cover, expanded against
     * project config: both criteria set → one cell; section only → every entry
     * type in that section; type only → every section using that type (Craft 5
     * shares entry types across sections); neither → every cell there is.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        $section = $link->criterion(self::CRITERIA_SECTION);
        $entryType = $link->criterion(self::CRITERIA_TYPE);

        $cells = [];

        foreach ($this->sectionEntryTypeMap() as $sectionHandle => $typeHandles) {
            if ($section !== null && $sectionHandle !== $section) {
                continue;
            }

            foreach ($typeHandles as $typeHandle) {
                if ($entryType !== null && $typeHandle !== $entryType) {
                    continue;
                }

                $cells[] = $sectionHandle . ' ' . $typeHandle;
            }
        }

        return array_values(array_unique($cells));
    }

    /**
     * Project-config view {@see claimCells()} expands against: section handle =>
     * list of entry-type handles in that section. Isolated as a seam so the
     * expansion is unit-testable without a booted Craft. Craft 4/5 differ only in
     * the service that lists sections — routed through {@see Compat}.
     *
     * @return array<string, list<string>>
     */
    protected function sectionEntryTypeMap(): array
    {
        $map = [];

        foreach (Compat::getAllSections() as $section) {
            $handles = [];

            foreach ($section->getEntryTypes() as $entryType) {
                $handles[] = $entryType->handle;
            }

            $map[$section->handle] = $handles;
        }

        return $map;
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

        return $this->scopeToLink($query, $link, $siteId)->one();
    }

    /**
     * A Craft Single holds exactly one entry, which the section criterion already
     * names — so a link scoped to one needs no match value, the same way a Global
     * Set link doesn't ({@see ElementTargetInterface::requiresMatch()}).
     *
     * Anything else needs a match: a channel or structure names a set of entries,
     * and so does an unresolved criterion. A section handle that doesn't resolve
     * (unset, or since removed) answers TRUE deliberately — "can't tell" must not
     * quietly relax the requirement on a half-configured link.
     */
    public function requiresMatch(Link $link): bool
    {
        return $this->section($link)?->type !== Section::TYPE_SINGLE;
    }

    /**
     * The single entry the link's section holds. Scoped through the same
     * {@see scopeToLink()} definition the match lookup uses, so "which entries this
     * link owns" stays one rule — a Single's section simply narrows it to one row.
     */
    public function findWithoutMatch(Link $link, ?int $siteId = null): ?Entry
    {
        if ($link->criterion(self::CRITERIA_SECTION) === null) {
            return null;
        }

        return $this->scopeToLink(Entry::find()->status(null), $link, $siteId)->one();
    }

    /**
     * The link's section, or null when it isn't set or no longer exists. Isolated
     * as a seam so {@see requiresMatch()} is testable without a booted Craft.
     */
    protected function section(Link $link): ?Section
    {
        $handle = $link->criterion(self::CRITERIA_SECTION);

        return $handle !== null ? Compat::getSectionByHandle($handle) : null;
    }

    /**
     * The link's ownership scope as query criteria: its section/type criteria
     * (each only when set) plus the site scope — one site for a site-scoped run,
     * otherwise one row per canonical entry across sites. THE definition of
     * "which entries this link owns", so {@see findByMatchValue()} and
     * {@see missingElementsQuery()} can't drift apart.
     */
    protected function scopeToLink(EntryQuery $query, Link $link, ?int $siteId): EntryQuery
    {
        if (($section = $link->criterion(self::CRITERIA_SECTION)) !== null) {
            $query->section($section);
        }

        if (($type = $link->criterion(self::CRITERIA_TYPE)) !== null) {
            $query->type($type);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query;
    }

    /**
     * Candidate set for the missing-elements sweep: every entry this link owns
     * (the same {@see scopeToLink()} scoping {@see findByMatchValue()} uses),
     * minus the ids the run just saw. Returns null only when the link has no
     * match attribute at all — such a link can't sync, so there's nothing to
     * sweep.
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
     *
     * The ids the run just touched are excluded with the query's `not` prefix;
     * an empty seen-set therefore leaves the whole owned set as candidates.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = $this->scopeToLink(Entry::find(), $link, $siteId);

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
     * The entry identifiers, from the one list an Entries RELATION field offers
     * too ({@see NativeAttributes::entryMatchable()}), gated on the link's
     * resolved entry type — a title can be generated from a titleFormat and a
     * slug field can be hidden per type. Unresolved criteria fall back to
     * id-only: there's no type yet whose settings could gate anything, and
     * offering the rest would be guessing.
     *
     * The title option is labelled from the field layout, so it reads as what
     * the editor actually sees.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return parent::matchableNativeAttributes($link);
        }
        [, $entryType] = $resolved;

        $titleElement = $entryType->getFieldLayout()?->getFirstElementByType(EntryTitleField::class);

        return NativeAttributes::entryMatchable([$entryType], $titleElement?->label());
    }

    /**
     * Custom fields come from the resolved entry type's own field layout, so
     * they keep their entry-editor grouping; an unresolvable section/type leaves
     * the natives alone — and un-gated, since there's no type yet whose
     * visibility settings could hide any of them.
     */
    public function getMappableFields(Link $link): array
    {
        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return $this->nativeFieldDefinitions()->toArray();
        }
        [, $entryType] = $resolved;

        return array_merge(
            $this->nativeFieldDefinitions($entryType)->toArray(),
            $this->customFieldDescriptors(
                $entryType->getFieldLayout(),
                Craft::t('influx', 'Content'),
            ),
        );
    }

    /**
     * The resolved entry type's layout — entry types own their own field layout in
     * Craft 5, and a section without a resolvable type has none to report.
     */
    public function fieldLayout(Link $link): ?FieldLayout
    {
        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return null;
        }
        [, $entryType] = $resolved;

        return $entryType->getFieldLayout();
    }

    /**
     * Feed titles routinely overflow Craft's 255-char title column —
     * truncate safely instead of letting the save fail. Mirrors feed-me's
     * title hygiene. An empty value clears the title: the feed is
     * authoritative, and the sync's validation-off saves let a blank one
     * persist.
     *
     * Like the other `parse{Handle}()` methods below, this is dispatched by
     * handle from {@see AbstractElementTarget::applyNativeAttribute()}.
     */
    protected function parseTitle(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        $new = $value === null ? null : StringHelper::safeTruncate((string) $value, 255);
        $changed = (string) ($element->title ?? '') !== (string) ($new ?? '');
        $element->title = $new;

        return $changed;
    }

    /**
     * Slugs straight from a feed are rarely slug-safe — normalize the same
     * way Craft does when auto-generating (respects limitAutoSlugsToAscii
     * and allowUppercaseInSlug). An empty value clears the slug, which Craft
     * then regenerates from the title on save.
     */
    protected function parseSlug(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

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
     *
     * Change detection compares the element's current author ids against the
     * intended id computed here — reading the author back off an unsaved
     * element is unreliable. An empty value, or one matching nobody, clears the
     * author.
     */
    protected function parseAuthor(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Entry $element */
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
     * An empty value clears the date — the feed is authoritative. Parsing is
     * {@see Date::tryParse()}, the same rule the custom Date field uses; the
     * policy for its null differs on purpose: an unparseable value is a no-op
     * here, because malformed feed data must not wipe a stored native date (the
     * field strategy throws instead, surfacing an error row).
     */
    protected function assignDate(ElementInterface $element, string $attr, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);
        $before = $element->{$attr};

        if ($value === null || $value === '') {
            $element->{$attr} = null;

            return $before !== null;
        }

        $parsed = Date::tryParse($value, $mapping->option('format'));

        if ($parsed === null) {
            return false;
        }

        $element->{$attr} = $parsed;

        return ! ($before instanceof DateTimeInterface) || $before->getTimestamp() !== $parsed->getTimestamp();
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

    /**
     * The Entry-native mappable attributes. Title / slug / enabled are declared
     * only when the resolved entry type actually shows them — Craft lets a type
     * hide each in the entry editor (`hasTitleField`, `showSlugField`,
     * `showStatusField`, feature-detected through {@see Compat} since the last
     * two postdate Craft 4.0) — so a hidden native never becomes a descriptor,
     * exactly like a custom field removed from the layout. Null $entryType
     * (unresolved criteria) declares everything: there's no type yet whose
     * settings could hide any of them.
     *
     * Consequences of leaving one out, all deliberate:
     *  - a link's stored mapping for that handle is dropped the next time the
     *    link is saved ({@see \GlueAgency\Influx\services\LinksService::pruneMappings()});
     *  - until that save the stale mapping still syncs. For `title` on a
     *    `hasTitleField = false` type that's a no-op with churn: Craft's own
     *    `Entry::beforeSave()` calls `updateTitle()`, which overwrites the mapped
     *    title with the type's `titleFormat` (or nulls it when there is none),
     *    while Influx still counts the write as a change and re-saves the element
     *    every run. Hidden slug / status mappings do still land until pruned.
     */
    protected function nativeFieldDefinitions(?EntryType $entryType = null): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), fn(MappingSchemaBuilder $group) => $group
                ->when(
                    $entryType === null || Compat::entryTypeShowsTitleField($entryType),
                    fn(MappingSchemaBuilder $builder) => $builder->text([
                        'handle' => 'title',
                        'name'   => Craft::t('app', 'Title'),
                    ]),
                )
                ->when(
                    $entryType === null || Compat::entryTypeShowsSlugField($entryType),
                    fn(MappingSchemaBuilder $builder) => $builder->text([
                        'handle' => 'slug',
                        'name'   => Craft::t('app', 'Slug'),
                    ]),
                )
                ->when(
                    $entryType === null || Compat::entryTypeShowsStatusField($entryType),
                    fn(MappingSchemaBuilder $builder) => $builder->select([
                        'handle'  => 'enabled',
                        'name'    => Craft::t('app', 'Enabled'),
                        'options' => [
                            'true'  => Craft::t('app', 'Enabled'),
                            'false' => Craft::t('app', 'Disabled'),
                        ],
                    ]),
                )
                ->text([
                    'handle' => 'postDate',
                    'name'   => Craft::t('app', 'Post Date'),
                    'extras' => fn(MappingSchemaBuilder $builder) => $builder->dateFormat(['options' => Date::formatOptions()]),
                ])
                ->text([
                    'handle' => 'expiryDate',
                    'name'   => Craft::t('app', 'Expiry Date'),
                    'extras' => fn(MappingSchemaBuilder $builder) => $builder->dateFormat(['options' => Date::formatOptions()]),
                ])
                ->element([
                    'handle'      => 'author',
                    'name'        => Craft::t('app', 'Author'),
                    'elementType' => User::class,
                    'extras'      => fn(MappingSchemaBuilder $builder)      => $builder->matchBy(['options' => $this->authorMatchOptions()]),
                ]));
    }

    /**
     * Match-by options for the native author dropdown. Built statically (no
     * Craft field instance to introspect) — the shared user identifiers
     * ({@see NativeAttributes::userMatchable()}), then any custom fields on the
     * global User layout so unique handles like an external `importId` can
     * match.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    protected function authorMatchOptions(): array
    {
        $groups = [
            [
                'label'   => Craft::t('influx', 'User'),
                'kind'    => 'element',
                'options' => NativeAttributes::userMatchable(),
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
