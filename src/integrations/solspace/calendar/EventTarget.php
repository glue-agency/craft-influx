<?php

namespace GlueAgency\Influx\integrations\solspace\calendar;

use Carbon\Carbon;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\models\FieldLayout;
use DateTimeInterface;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\CategoryTarget;
use GlueAgency\Influx\targets\EntryTarget;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\Db\EventQuery;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Models\CalendarModel;

/**
 * Target for Solspace Calendar's `Solspace\Calendar\Elements\Event`.
 *
 * Recognized elementCriteria key — {@see CRITERIA_CALENDAR}, the handle of the
 * calendar (required for new events, since the calendar carries the field layout,
 * the title settings and the per-site settings a new event is seeded from). This
 * target OWNS that key name: every reader goes through {@see Link::criterion()}
 * with the constant below.
 *
 * A calendar IS the ownership boundary — the same relationship a category group
 * has to its categories ({@see CategoryTarget}) — so this target sweeps: every
 * event in the configured calendar is managed by the link, which is what makes
 * "everything the feed didn't mention" a safe set to act on. Creating and
 * multi-site both stay at the base's defaults too: events are ordinary content a
 * feed may bring into being, and `Event::isLocalized()` is true with a
 * per-site-settings row per calendar, so a link to one can carry site endpoints.
 *
 * NO TYPE NARROWING on any inherited or interface method below, and no
 * `instanceof Event`. PHP verifies return- and parameter-type variance when the
 * CLASS is declared, and that verification autoloads the narrowed type — so a
 * `: ?Event` return would fatal this file on a site without Calendar, which is
 * precisely the site {@see isAvailable()} exists to stay quiet on. The Calendar
 * classes are named in bodies (runtime-only) and in `use` statements (a
 * compile-time string) instead; new protected methods may type-hint them, since
 * there's no parent signature to check them against.
 *
 * RECURRENCE IS DELIBERATELY OUT OF SCOPE. `rrule` / `freq` / `interval` /
 * `count` / `until` / `byDay` / `byMonth` / `byMonthDay` / `byYearDay` are not
 * offered as mappable natives, because they aren't values — they're one
 * interdependent rule spread over nine columns, where `rrule` is the RFC string
 * DERIVED from the others and the legal combinations depend on `freq` (`byDay`
 * means something under WEEKLY and something else under MONTHLY). A mapping row
 * owns one handle and knows nothing about its neighbours, so nine independent
 * rows could only ever write incoherent halves of a rule — which is why
 * Calendar's own Feed Me integration takes a single RFC `rrule` string and
 * reassembles the rest from it on a before-save hook, rather than mapping the
 * columns. If Influx grows recurrence it wants that shape: one row, parsed and
 * expanded as a unit. Until then a synced event is a single occurrence, and an
 * existing recurring event's rule is left exactly as an editor set it.
 */
class EventTarget extends AbstractElementTarget
{
    public const CRITERIA_CALENDAR = 'calendar';

    public static function elementType(): string
    {
        return Event::class;
    }

    /**
     * Narrower than the base's class check on purpose: Calendar's package can be
     * in the vendor tree with the PLUGIN uninstalled or disabled, and the base's
     * `is_subclass_of()` answers "the class loads", not "the plugin is running".
     * In that state every read below would reach `Calendar::getInstance()->…` on
     * null — {@see criteriaSchema()} first, since the builder asks it for every
     * element type before any link exists. Solspace guard the same distinction in
     * their own Feed Me integration (`if (Calendar::getInstance())`).
     *
     * Order is load-bearing: the parent's class check runs first, so a site
     * without the package never reaches for the plugin class at all.
     */
    public static function isAvailable(): bool
    {
        return parent::isAvailable() && Calendar::getInstance() !== null;
    }

    /**
     * "Calendar Event" rather than the element class's own `displayName()`, which
     * is the bare word "Event" — too thin for a dropdown listing it beside Entry,
     * Asset and Category, and ambiguous with the other plugins that ship an
     * element of that name. Matches how Solspace label their own Feed Me
     * integration.
     */
    public static function friendlyName(): string
    {
        return Craft::t('influx', 'Calendar Event');
    }

    /**
     * Events are scoped by their calendar — the one dropdown the builder's General
     * tab renders for this element type.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_CALENDAR];
    }

    /** @return list<array> */
    public static function criteriaSchema(): array
    {
        $options = [self::criteriaPlaceholder()];

        foreach (static::calendarModels() as $calendar) {
            $options[] = ['value' => $calendar->handle, 'label' => $calendar->name];
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_CALENDAR,
                'label'   => Craft::t('influx', 'Calendar'),
                'options' => $options,
            ])
            ->toArray();
    }

    public function criteriaLabel(Link $link): ?string
    {
        $handle = $link->criterion(self::CRITERIA_CALENDAR);

        if (! $handle) {
            return null;
        }

        return $this->calendarByHandle($handle)?->name ?? $handle;
    }

    /**
     * Structural targeting: the element is an Event this link handles, inside the
     * link's configured calendar (the criterion only bites when set). Says nothing
     * about the match value — that gap is {@see claimsElement()}'s.
     *
     * The calendar is compared by ID rather than through `Event::getCalendar()`,
     * which is typed `: CalendarModel` but returns whatever `getCalendarById()`
     * hands back — so an event whose calendar is unset or since deleted makes it
     * throw a TypeError. This predicate runs on every element edit screen in the
     * CP (the mapped-field indicators ask it of whatever is being edited), so it
     * has to answer "not mine" instead of breaking an unrelated page. Same trap,
     * same fix as {@see CategoryTarget::targetsElement()}.
     *
     * `is_a()` against {@see elementType()} rather than `instanceof Event`: it
     * keeps the class named in exactly one place, and reads a string so this file
     * stays loadable with Calendar absent.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! is_a($element, static::elementType())) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        /** @var Event $element */
        $handle = $link->criterion(self::CRITERIA_CALENDAR);

        return $handle === null || $this->calendarByHandle($handle)?->id === $element->calendarId;
    }

    /**
     * Events partition by calendar, so a link's claim is the calendar it names —
     * or every calendar there is when it names none.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        $calendar = $link->criterion(self::CRITERIA_CALENDAR);

        if ($calendar !== null) {
            return [$calendar];
        }

        $cells = [];

        foreach (static::calendarModels() as $candidate) {
            $cells[] = $candidate->handle;
        }

        return $cells;
    }

    /** @return Event|null */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Event::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        return $this->scopeToLink($query, $link, $siteId)->one();
    }

    /**
     * Candidate set for the missing-elements sweep: every event this link owns
     * ({@see scopeToLink()}, the same scoping {@see findByMatchValue()} uses),
     * minus the ids the run just saw. Null only when the link has no match
     * attribute — such a link can't sync, so there's nothing to sweep.
     *
     * An event with an EMPTY match value is a candidate too, for the reason
     * {@see EntryTarget::missingElementsQuery()} spells out: the calendar scope
     * already answers "is this ours", and no feed item can ever match a blank key.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = $this->scopeToLink(Event::find(), $link, $siteId);

        if ($seenIds !== []) {
            $query->id(array_merge(['not'], $seenIds));
        }

        return $query;
    }

    /**
     * `Event::create()` rather than `new Event()`: the factory seeds `startDate`,
     * `endDate`, `allDay`, `postDate`, `authorId` and `enabled`, and the first two
     * are REQUIRED by `Event::rules()` — so a bare constructor produces an element
     * that can't pass the engine's validating save
     * ({@see AbstractElementTarget::save()}) even when the feed maps every column
     * an operator can see. It also resolves the site's `enabledByDefault` off the
     * calendar's per-site settings, which nothing here could do for it.
     *
     * The link's calendar wins over the factory's fallback: `create()` reads
     * `$calendarId ?? getFirstCalendarId()`, so passing a resolved id is what
     * keeps a half-configured link from quietly seeding events into whichever
     * calendar happens to sort first — {@see requireCalendar()} refuses instead.
     *
     * @return Event
     */
    public function buildNew(Link $link, ?int $siteId = null): ElementInterface
    {
        return Event::create($siteId, $this->requireCalendar($link)->id);
    }

    /**
     * The event identifiers, and only the three an `EventQuery` can actually
     * resolve: `id`, plus `title` and `slug` off `elements_sites` (Calendar's
     * events have titles and URIs, and its own editor always renders a slug
     * field). `uri` is left out where {@see NativeAttributes::entryMatchable()}
     * offers it, because a calendar's URI format is opt-in per site and empty by
     * default — so unlike a section, the column is null on the common install
     * rather than the exception, and the option would promise a lookup that
     * matches nothing.
     *
     * `title` is gated on the calendar's own `hasTitleField` for the same reason
     * {@see nativeFieldDefinitions()} gates the writable row: without it the title
     * is a `titleFormat` render, so matching on one would key items to a value
     * Calendar recomputes on every save. Unresolved criteria fall back to the
     * base's `id`-only list — there's no calendar yet whose settings could gate
     * anything, and offering the rest would be guessing (the call
     * {@see EntryTarget::matchableNativeAttributes()} makes).
     *
     * Declared here rather than as a `NativeAttributes::eventMatchable()`. That
     * class exists to state a list ONCE for the two surfaces that both want it —
     * a relation field's "Match by" and a target — and a third-party element type
     * has only the target: Influx has no relation strategy that resolves events,
     * since Calendar's own field types aren't `BaseRelationField`s. Keeping it
     * here also keeps `src/schema` free of any Calendar reference, so no core
     * class can be the thing that reaches for a missing plugin.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        $calendar = $this->calendar($link);
        $options = parent::matchableNativeAttributes($link);

        if (! $calendar) {
            return $options;
        }

        if ($calendar->hasTitleField) {
            $options[] = ['value' => 'title', 'label' => Craft::t('app', 'Title') . ' (title)'];
        }

        $options[] = ['value' => 'slug', 'label' => Craft::t('app', 'Slug') . ' (slug)'];

        return $options;
    }

    /**
     * Custom fields come from the configured calendar's own field layout, so they
     * keep their event-editor grouping; an unresolvable calendar leaves the
     * natives alone.
     *
     * The layout comes off the CALENDAR, not off `Event::getFieldLayout()` — the
     * element's getter clones the calendar's layout and splices a native Title
     * field into it, which this side declares as a native of its own.
     */
    public function getMappableFields(Link $link): array
    {
        $calendar = $this->calendar($link);

        return array_merge(
            $this->nativeFieldDefinitions($calendar)->toArray(),
            $this->customFieldDescriptors(
                $calendar?->getFieldLayout(),
                Craft::t('influx', 'Content'),
            ),
        );
    }

    public function fieldLayout(Link $link): ?FieldLayout
    {
        return $this->calendar($link)?->getFieldLayout();
    }

    /**
     * The link's ownership scope as query criteria: its calendar (only when set)
     * plus the site scope — one site for a site-scoped run, otherwise one row per
     * canonical event across sites. THE definition of "which events this link
     * owns", so {@see findByMatchValue()} and {@see missingElementsQuery()} can't
     * drift apart.
     *
     * OCCURRENCE EXPANSION IS TURNED OFF, and that is not a performance tweak.
     * `EventQuery::all()` (and `one()`, which goes through it) returns one element
     * per OCCURRENCE of a recurring event — each a `cloneForDate()` copy whose
     * `startDate` / `endDate` have been moved to that occurrence, while
     * `Event::afterSave()` writes `startDate` straight back to the row. So
     * {@see findByMatchValue()} would hand the engine a clone of an existing
     * recurring event, and the first mapped change to anything else would silently
     * overwrite that event's base date with one of its occurrences. Recurrence
     * being out of mapping scope is exactly why this matters: an update must leave
     * an existing recurring event's dates as its editor set them.
     *
     * Set on the shared scope rather than at that one call site. The sweep escapes
     * the same trap only incidentally — `Db::batch()` builds a `BatchQueryResult`,
     * which runs the prepared SQL and populates rows itself instead of calling
     * `all()` — and a reader that isn't so lucky shouldn't have to know. Calendar's
     * own Feed Me integration turns none of this off, which is worth knowing if the
     * two are ever compared.
     *
     * The calendar filter goes through `setCalendar()`, which matches the calendar
     * table's `handle` column — the criterion's own vocabulary, no id resolution,
     * and a handle that no longer exists narrows to nothing rather than silently
     * widening the scope to every event on the site.
     */
    protected function scopeToLink(EventQuery $query, Link $link, ?int $siteId): EventQuery
    {
        $query->setLoadOccurrences(false);

        if (($calendar = $link->criterion(self::CRITERIA_CALENDAR)) !== null) {
            $query->setCalendar($calendar);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query;
    }

    protected function parseStartDate(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignEventDate($element, 'startDate', $item, $mapping);
    }

    protected function parseEndDate(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignEventDate($element, 'endDate', $item, $mapping);
    }

    protected function parsePostDate(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignDate($element, 'postDate', $item, $mapping);
    }

    /**
     * An event's start/end date, which needs its own assignment rather than the
     * shared {@see AbstractElementTarget::assignDate()} on two counts.
     *
     * It must land as a CARBON, not a `DateTime`. Calendar treats these two
     * attributes as Carbon throughout — `Event::validateDates()` calls
     * `diffInDays()` on them and `Event::afterSave()` calls `toDateTimeString()`
     * — so a plain `DateTime` doesn't fail validation, it fatals inside it. The
     * conversion is Solspace's own, from their Feed Me integration ("Calendar
     * expects dates as Carbon object, not DateTime"): take the parsed value's WALL
     * CLOCK and label it UTC, which is how Calendar stores event dates (a floating
     * time, shown back to editors unshifted). Hence the string comparison for
     * change detection: it compares exactly what `afterSave()` will write, where
     * comparing instants would report a change for every feed value whose timezone
     * differs from the stored label.
     *
     * And an empty value is a NO-OP rather than a clear. Both attributes are
     * required by `Event::rules()`, so clearing one turns every date-less feed row
     * into a failed save — and there is no such thing as an event without a start
     * date, the same call {@see \GlueAgency\Influx\targets\AssetTarget::parseUrl()}
     * makes about an asset without a file. On a new event that leaves the sane
     * pair `Event::create()` seeded; on an existing one it leaves the editor's.
     *
     * `'UTC'` as a literal rather than Calendar's own `DateHelper::UTC` constant:
     * the timezone name is what Carbon needs, and reaching into the plugin's
     * internal helpers for a three-letter string only adds something that can be
     * renamed underneath us.
     */
    protected function assignEventDate(ElementInterface $element, string $attr, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null || $value === '') {
            return false;
        }

        $parsed = Date::tryParse($value, $mapping->option('format'));

        if ($parsed === null) {
            return false;
        }

        $before = $element->{$attr};
        $element->{$attr} = new Carbon($parsed->format('Y-m-d H:i:s'), 'UTC');

        return ! ($before instanceof DateTimeInterface)
            || $before->format('Y-m-d H:i:s') !== $element->{$attr}->format('Y-m-d H:i:s');
    }

    /**
     * The all-day flag, coerced through {@see Lightswitch::coerce()} so a feed's
     * spelling of true means the same thing here as it does on a Lightswitch field
     * and on the `enabled` flag ({@see AbstractElementTarget::parseEnabled()}) —
     * which also means an addressed-but-empty value reads as false.
     *
     * Deliberately does NOT normalize the times to 00:00 / 23:59 the way
     * Calendar's own event builder does when the flag is switched on: that
     * transform belongs to whoever owns the dates, and here the feed does. A feed
     * that says "all day" and ships times is telling the truth about both.
     */
    protected function parseAllDay(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Event $element */
        $new = Lightswitch::coerce($mapping->resolve($item));
        $changed = (bool) $element->allDay !== $new;

        $element->allDay = $new;

        return $changed;
    }

    /**
     * Resolve the per-item author and assign it as `authorId`. Mirrors
     * {@see EntryTarget::parseAuthor()} — see there for why the change is detected
     * against the id computed here rather than by reading the relation back — with
     * the one difference that Calendar stores a single author id on the element
     * rather than Craft 5's authors relation, so there's no Compat seam.
     */
    protected function parseAuthor(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Event $element */
        $before = $element->getAuthorId();
        $newId = $this->resolveAuthorId($context, $item, $mapping);

        $element->authorId = $newId;

        return $before !== $newId;
    }

    /**
     * The author user id for one item. Mirrors
     * {@see EntryTarget::resolveAuthorId()}, including the split it documents: a
     * feed *node* value goes through the configured `match` strategy, while the
     * mapping's `default` is a user picked in the CP and so is matched by id
     * regardless.
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

    /**
     * Resolve a user by the given match strategy, memoized on the run's lookup
     * cache under the `author` scope — the same lookup and the same cache scope
     * {@see EntryTarget::findUser()} uses, so an entry link and an event link in
     * one run share resolved authors.
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
     * The Event-native mappable attributes.
     *
     * `title` is declared only when the calendar actually SHOWS a title field: a
     * calendar with `hasTitleField` off renders its title from `titleFormat`, and
     * `Event::beforeSave()` calls `updateTitle()` on every save — so a mapped
     * title there is overwritten while Influx still counts the write as a change
     * and re-saves the element every run. That's the same "natives come from what
     * the type actually shows" rule, and the same churn,
     * {@see EntryTarget::nativeFieldDefinitions()} documents at length for an entry
     * type's hidden title. A null $calendar (unresolved criteria) declares
     * everything: there's no calendar yet whose settings could hide any of it.
     *
     * `slug` is unconditional — Calendar's event editor always renders one — and
     * needs no normalizing parser of its own: Craft's own `SlugValidator` runs on
     * the engine's validating save and derives a clean slug (from the title, when
     * the value is blank), which is the same bargain {@see CategoryTarget} takes.
     *
     * `enabled` rides the inherited {@see AbstractElementTarget::parseEnabled()};
     * `startDate` / `endDate` / `postDate` carry the shared date-format extra;
     * `allDay` is a two-option select rather than a lightswitch so its row reads
     * like `enabled`'s and can express "not mapped".
     */
    protected function nativeFieldDefinitions(?CalendarModel $calendar = null): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), fn(MappingSchemaBuilder $group) => $group
                ->when(
                    $calendar === null || (bool) $calendar->hasTitleField,
                    fn(MappingSchemaBuilder $builder) => $builder->text([
                        'handle' => 'title',
                        'name'   => Craft::t('app', 'Title'),
                    ]),
                )
                ->text([
                    'handle' => 'slug',
                    'name'   => Craft::t('app', 'Slug'),
                ])
                ->select([
                    'handle'  => 'enabled',
                    'name'    => Craft::t('app', 'Enabled'),
                    'options' => [
                        'true'  => Craft::t('app', 'Enabled'),
                        'false' => Craft::t('app', 'Disabled'),
                    ],
                ])
                ->text([
                    'handle' => 'startDate',
                    'name'   => Craft::t('influx', 'Start Date'),
                    'extras' => fn(MappingSchemaBuilder $builder) => $builder->dateFormat(['options' => Date::formatOptions()]),
                ])
                ->text([
                    'handle' => 'endDate',
                    'name'   => Craft::t('influx', 'End Date'),
                    'extras' => fn(MappingSchemaBuilder $builder) => $builder->dateFormat(['options' => Date::formatOptions()]),
                ])
                ->select([
                    'handle'  => 'allDay',
                    'name'    => Craft::t('influx', 'All Day'),
                    'options' => [
                        'true'  => Craft::t('app', 'Yes'),
                        'false' => Craft::t('app', 'No'),
                    ],
                ])
                ->text([
                    'handle' => 'postDate',
                    'name'   => Craft::t('app', 'Post Date'),
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
     * Match-by options for the native author dropdown, built exactly as
     * {@see EntryTarget::authorMatchOptions()} builds them — the shared user
     * identifiers, then the custom fields on the global User layout so a unique
     * handle like an external `importId` can match.
     *
     * @return list<array{label: string, kind: string, options: list<array{value: string, label: string}>}>
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

    /**
     * Lenient calendar resolution for UI/read paths: an unset or unknown handle
     * yields null, so a half-configured link still reports its natives.
     */
    protected function calendar(Link $link): ?CalendarModel
    {
        $handle = $link->criterion(self::CRITERIA_CALENDAR);

        return $handle ? $this->calendarByHandle($handle) : null;
    }

    /**
     * Strict calendar resolution for the write path, naming the offending handle —
     * the same discipline {@see CategoryTarget::requireGroup()} applies to a group.
     *
     * @throws InfluxException when the calendar criteria is missing or unknown.
     */
    protected function requireCalendar(Link $link): CalendarModel
    {
        $handle = $link->criterion(self::CRITERIA_CALENDAR);

        if (! $handle) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.calendar for Calendar Event targets.",
            );
        }

        $calendar = $this->calendarByHandle($handle);

        if (! $calendar) {
            throw new InfluxException("Calendar '{$handle}' does not exist.");
        }

        return $calendar;
    }

    /**
     * The by-handle lookup, isolated as a seam so the resolution above is testable
     * without a booted Craft or an installed Calendar — the same seam
     * {@see CategoryTarget::groupByHandle()} is.
     */
    protected function calendarByHandle(string $handle): ?CalendarModel
    {
        return Calendar::getInstance()?->calendars->getCalendarByHandle($handle);
    }

    /**
     * Every calendar, as the second seam onto Calendar's service. STATIC because
     * {@see criteriaSchema()} is: the CP asks it per element type before any link
     * exists. {@see claimCells()} reads it through `static::` so both surfaces
     * answer from one lookup, and a spec can substitute the list for either.
     *
     * Keyed by calendar id, the way Calendar's own cache hands them back — every
     * reader here iterates, so the keys are carried rather than flattened.
     *
     * @return array<int, CalendarModel>
     */
    protected static function calendarModels(): array
    {
        return Calendar::getInstance()?->calendars->getAllCalendars() ?? [];
    }
}
