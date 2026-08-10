<?php

namespace GlueAgency\Influx\Tests\unit\integrations\solspace\calendar;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\models\FieldLayout;
use DateTime;
use DateTimeZone;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\integrations\solspace\calendar\EventTarget;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Models\CalendarModel;

require_once __DIR__ . '/CalendarStubs.php';

/**
 * EventTarget: the calendar is both the scope and the ownership boundary, and the
 * two things that make Calendar's Event different from a Craft element are its
 * REQUIRED Carbon start/end dates and the factory that seeds them.
 *
 * Calendar isn't a dependency of this repo, so its three classes come from
 * {@see CalendarStubs} in their real namespaces — which is what lets the target's
 * own `is_a()` / `Event::create()` / `$event->calendarId` run for real. Calendar's
 * SERVICES are reached through the target's protected seams instead, so the spec
 * needs no booted Craft and no installed plugin.
 *
 * Dates are always mapped with an explicit `format`: the auto-detect branch of
 * {@see \GlueAgency\Influx\fields\Date::tryParse()} goes through Craft's
 * `DateTimeHelper`, which needs a booted app for its timezone lookup (the same
 * line {@see \GlueAgency\Influx\Tests\unit\fields\DateFieldTest} draws).
 */
class EventTargetTest extends Unit
{
    // -- availability ---------------------------------------------------------

    public function testTheTargetStaysInertWhileTheCalendarPluginIsNotInstalled(): void
    {
        // The stubs above make the element class loadable — the package-in-vendor
        // half of the gate. That is deliberately NOT enough: Calendar can be
        // required and its plugin uninstalled or disabled, and in that state every
        // read on this target would reach Calendar::getInstance()->calendars on
        // null. It is also the state this whole suite runs in, which is what keeps
        // the target out of the registry for every other spec.
        $this->assertTrue(class_exists(Event::class));
        $this->assertFalse(EventTarget::isAvailable());
    }

    // -- criteria + targeting -------------------------------------------------

    public function testTheCalendarCriterionGatesBothPredicates(): void
    {
        $target = $this->targetWithCalendars(['events' => 'Events', 'training' => 'Training']);
        $link = $this->link(['calendar' => 'events']);

        // In scope, no match value: structurally targeted, not yet claimed.
        $event = $this->event('events', null);
        $this->assertTrue($target->targetsElement($link, $event));
        $this->assertFalse($target->claimsElement($link, $event));

        $event = $this->event('events', 'abc');
        $this->assertTrue($target->targetsElement($link, $event));
        $this->assertTrue($target->claimsElement($link, $event));

        // Wrong calendar — out of scope even with a match value.
        $event = $this->event('training', 'abc');
        $this->assertFalse($target->targetsElement($link, $event));
        $this->assertFalse($target->claimsElement($link, $event));
    }

    public function testAnUnscopedLinkTargetsAnyEvent(): void
    {
        $this->assertTrue(
            $this->targetWithCalendars([])->targetsElement($this->link([]), $this->event('anything', 'abc')),
        );
    }

    public function testAnEventWithNoCalendarIsNotTargeted(): void
    {
        // This predicate runs on every element edit screen, so an event whose
        // calendar is unset (or since deleted) has to answer "not mine" rather
        // than let Event::getCalendar() throw on the way past.
        $target = $this->targetWithCalendars(['events' => 'Events']);
        $event = $this->event('events', 'abc');
        $event->calendarId = null;

        $this->assertFalse($target->targetsElement($this->link(['calendar' => 'events']), $event));
    }

    public function testAnEventInACalendarThatNoLongerExistsIsNotTargeted(): void
    {
        // Same shape from the other side: the LINK names a calendar the site has
        // dropped, so nothing resolves and nothing may be claimed.
        $target = $this->targetWithCalendars([]);

        $this->assertFalse(
            $target->targetsElement($this->link(['calendar' => 'events']), $this->event('events', 'abc')),
        );
    }

    public function testANonEventIsNeverTargeted(): void
    {
        $notAnEvent = new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };

        $this->assertFalse(
            $this->targetWithCalendars(['events' => 'Events'])
                ->targetsElement($this->link(['calendar' => 'events']), $notAnEvent),
        );
    }

    public function testClaimCellsAreTheNamedCalendarOrEveryCalendar(): void
    {
        $target = $this->targetWithCalendars(['events' => 'Events', 'training' => 'Training']);

        $this->assertSame(['events'], $target->claimCells($this->link(['calendar' => 'events'])));
        $this->assertSame(['events', 'training'], $target->claimCells($this->link([])));
    }

    public function testTheCriteriaSchemaIsOneCalendarSelectLedByThePlaceholder(): void
    {
        // The builder renders this for every element type before any link exists,
        // so a wrong handle here silently breaks the General tab.
        $target = $this->targetWithCalendars(['events' => 'Events', 'training' => 'Training']);
        $nodes = $target::criteriaSchema();

        $this->assertCount(1, $nodes);
        $this->assertSame(EventTarget::CRITERIA_CALENDAR, $nodes[0]['handle']);
        $this->assertSame([EventTarget::CRITERIA_CALENDAR], $target::criteriaKeys());
        $this->assertSame('', $nodes[0]['options'][0]['value']);
        $this->assertSame(
            [['value' => 'events', 'label' => 'Events'], ['value' => 'training', 'label' => 'Training']],
            array_slice($nodes[0]['options'], 1),
        );
    }

    public function testCriteriaLabelNamesTheCalendarAndFallsBackToTheHandle(): void
    {
        $target = $this->targetWithCalendars(['events' => 'Events']);

        $this->assertNull($target->criteriaLabel($this->link([])));
        $this->assertSame('Events', $target->criteriaLabel($this->link(['calendar' => 'events'])));
        // A calendar removed since the link was configured still reads as something.
        $this->assertSame('training', $target->criteriaLabel($this->link(['calendar' => 'training'])));
    }

    // -- natives --------------------------------------------------------------

    public function testEveryNativeIsDeclaredForATitledCalendar(): void
    {
        $this->assertSame(
            ['title', 'slug', 'enabled', 'startDate', 'endDate', 'allDay', 'postDate', 'author'],
            $this->nativeHandles($this->calendar('events', 'Events', true)),
        );
    }

    public function testACalendarWithNoTitleFieldDropsTheTitleNative(): void
    {
        // Calendar renders that title from the calendar's titleFormat and
        // Event::beforeSave() rewrites it on every save, so a mapped title there
        // is a no-op that still counts as a change and re-saves the event.
        $handles = $this->nativeHandles($this->calendar('events', 'Events', false));

        $this->assertNotContains('title', $handles);
        $this->assertContains('slug', $handles);
        $this->assertContains('startDate', $handles);
    }

    public function testUnresolvedCriteriaDeclareEveryNative(): void
    {
        // No calendar resolved yet, so no calendar setting can hide anything.
        $this->assertContains('title', $this->nativeHandles(null));
    }

    public function testRecurrenceIsNeverOffered(): void
    {
        $handles = $this->nativeHandles($this->calendar('events', 'Events', true));

        foreach (['rrule', 'freq', 'interval', 'count', 'until', 'byDay', 'byMonth', 'byMonthDay', 'byYearDay'] as $handle) {
            $this->assertNotContains($handle, $handles);
        }
    }

    public function testTheMatchableTitleFollowsTheSameTitleGate(): void
    {
        $target = $this->targetWithCalendars(['events' => 'Events'], titled: true);
        $values = array_column($target->matchableNativeAttributes($this->link(['calendar' => 'events'])), 'value');
        $this->assertSame(['id', 'title', 'slug'], $values);

        $target = $this->targetWithCalendars(['events' => 'Events'], titled: false);
        $values = array_column($target->matchableNativeAttributes($this->link(['calendar' => 'events'])), 'value');
        $this->assertSame(['id', 'slug'], $values);
    }

    public function testAnUnresolvedCalendarOffersOnlyTheIdToMatchOn(): void
    {
        // There's no calendar yet whose settings could gate anything, and offering
        // the rest would be guessing.
        $target = $this->targetWithCalendars([]);

        $this->assertSame(
            [['value' => 'id', 'label' => 'ID (id)']],
            $target->matchableNativeAttributes($this->link(['calendar' => 'events'])),
        );
    }

    public function testTheFieldLayoutComesFromTheCalendar(): void
    {
        $layout = new FieldLayout();
        $target = $this->targetWithCalendars(['events' => 'Events'], layout: $layout);

        $this->assertSame($layout, $target->fieldLayout($this->link(['calendar' => 'events'])));
        $this->assertNull($target->fieldLayout($this->link([])));
    }

    // -- dates ----------------------------------------------------------------

    public function testStartDateLandsAsACarbonOnTheFeedsWallClock(): void
    {
        // Calendar calls diffInDays() on these two while validating and
        // toDateTimeString() on them while saving, so a plain DateTime doesn't
        // fail the save — it fatals inside it.
        $event = $this->event('events', 'abc');

        $this->assertTrue($this->applyDate($event, 'startDate', '2026-03-01 09:30:00'));
        $this->assertInstanceOf('Carbon\Carbon', $event->startDate);
        $this->assertSame('2026-03-01 09:30:00', $event->startDate->toDateTimeString());
        $this->assertSame('UTC', $event->startDate->getTimezone()->getName());
    }

    public function testEndDateTakesTheSameTreatment(): void
    {
        $event = $this->event('events', 'abc');

        $this->assertTrue($this->applyDate($event, 'endDate', '2026-03-01 17:00:00'));
        $this->assertSame('2026-03-01 17:00:00', $event->endDate->toDateTimeString());
    }

    public function testReapplyingTheSameStartDateIsNotAChange(): void
    {
        $event = $this->event('events', 'abc');
        $this->applyDate($event, 'startDate', '2026-03-01 09:30:00');

        $this->assertFalse(
            $this->applyDate($event, 'startDate', '2026-03-01 09:30:00'),
            'Comparison is on the wall clock actually stored, so a re-sync is a no-op.',
        );
        $this->assertTrue($this->applyDate($event, 'startDate', '2026-03-01 09:31:00'));
    }

    public function testAnEmptyStartDateIsANoOpRatherThanAClear(): void
    {
        // Event::rules() requires both dates, and there is no such thing as an
        // event without a start — so the feed's silence leaves what was seeded.
        $event = $this->event('events', 'abc');
        $seeded = new DateTime('2026-01-01 08:00:00', new DateTimeZone('UTC'));
        $event->startDate = $seeded;

        $this->assertFalse($this->applyDate($event, 'startDate', null));
        $this->assertSame($seeded, $event->startDate);
    }

    public function testAnUnparseableStartDateIsANoOp(): void
    {
        // Malformed feed data must not overwrite a stored native date.
        $event = $this->event('events', 'abc');
        $seeded = new DateTime('2026-01-01 08:00:00', new DateTimeZone('UTC'));
        $event->startDate = $seeded;

        $this->assertFalse($this->applyDate($event, 'startDate', 'not-a-date'));
        $this->assertSame($seeded, $event->startDate);
    }

    public function testPostDateRidesTheSharedDateAssignmentAndIsClearable(): void
    {
        // Nothing in Calendar treats postDate as a Carbon — only format() is ever
        // called on it — so it keeps the base's semantics, empty-clears included.
        $event = $this->event('events', 'abc');

        $this->assertTrue($this->applyDate($event, 'postDate', '2026-03-01 09:30:00'));
        $this->assertInstanceOf(DateTime::class, $event->postDate);

        $this->assertTrue($this->applyDate($event, 'postDate', null));
        $this->assertNull($event->postDate);
    }

    // -- allDay ---------------------------------------------------------------

    public function testAllDayIsCoercedLikeEveryOtherFlag(): void
    {
        $event = $this->event('events', 'abc');

        $this->assertTrue($this->applyAllDay($event, ['whole_day' => 'YES']));
        $this->assertTrue($event->allDay);

        $this->assertFalse(
            $this->applyAllDay($event, ['whole_day' => '1']),
            'Re-applying the same flag is not a change.',
        );
        $this->assertTrue($event->allDay);

        $this->assertTrue($this->applyAllDay($event, ['whole_day' => 'no']));
        $this->assertFalse($event->allDay);

        $event->allDay = true;
        $this->assertTrue(
            $this->applyAllDay($event, []),
            'An addressed-but-empty value coerces to false.',
        );
        $this->assertFalse($event->allDay);
    }

    // -- construction ---------------------------------------------------------

    public function testBuildNewGoesThroughTheFactoryWithTheLinksCalendar(): void
    {
        // A bare `new Event()` can't pass the engine's validating save: startDate
        // and endDate are required and only the factory seeds them. Passing the
        // resolved id is what stops create() falling back to whichever calendar
        // sorts first.
        Event::$created = [];
        $target = $this->targetWithCalendars(['events' => 'Events']);

        $event = $target->buildNew($this->link(['calendar' => 'events']), 7);

        $this->assertSame([['siteId' => 7, 'calendarId' => self::calendarId('events')]], Event::$created);
        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame(self::calendarId('events'), $event->calendarId);
    }

    public function testBuildNewRefusesALinkWithNoCalendar(): void
    {
        $this->expectException(InfluxException::class);

        $this->targetWithCalendars(['events' => 'Events'])->buildNew($this->link([]));
    }

    public function testBuildNewRefusesACalendarThatDoesNotExist(): void
    {
        $this->expectException(InfluxException::class);

        $this->targetWithCalendars([])->buildNew($this->link(['calendar' => 'events']));
    }

    // -- fixtures -------------------------------------------------------------

    protected function link(array $criteria): Link
    {
        return FakeLink::make([
            'elementType'     => Event::class,
            'elementCriteria' => $criteria,
            'match'           => ['attribute' => 'importId'],
        ]);
    }

    protected function context(EventTarget $target): SyncContext
    {
        return new SyncContext(link: $this->link(['calendar' => 'events']), target: $target);
    }

    /**
     * An event in the given calendar. Calendars are identified by a stable id
     * derived from the handle, so the target's id comparison lines up with what
     * {@see targetWithCalendars()} hands back for the same handle.
     */
    protected function event(string $calendar, mixed $match): Event
    {
        $event = new Event();
        $event->calendarId = self::calendarId($calendar);
        // The link's match attribute is `importId`; a real property, so the target
        // reads it directly rather than through the field magic getter.
        $event->importId = $match;

        return $event;
    }

    /** A handle's stand-in id — one rule, both sides of the comparison. */
    protected static function calendarId(string $handle): int
    {
        return crc32($handle);
    }

    protected function calendar(string $handle, string $name, bool $titled, ?FieldLayout $layout = null): CalendarModel
    {
        $calendar = new CalendarModel();
        $calendar->id = self::calendarId($handle);
        $calendar->handle = $handle;
        $calendar->name = $name;
        $calendar->hasTitleField = $titled;
        $calendar->fieldLayout = $layout;

        return $calendar;
    }

    /**
     * A target whose two Calendar-service seams answer from a handle => name map.
     * `authorMatchOptions()` is neutralised for the reason
     * {@see \GlueAgency\Influx\Tests\unit\targets\EntryNativeVisibilityTest} gives:
     * it reads the global User field layout.
     *
     * @param array<string, string> $calendars
     */
    protected function targetWithCalendars(array $calendars, bool $titled = true, ?FieldLayout $layout = null): EventTarget
    {
        $target = new class() extends EventTarget {
            /** @var array<string, string> */
            public static array $calendars = [];

            public static bool $titled = true;

            public static ?FieldLayout $layout = null;

            /** @return list<MappableField> */
            public function natives(?CalendarModel $calendar): array
            {
                return $this->nativeFieldDefinitions($calendar)->toArray();
            }

            protected function calendarByHandle(string $handle): ?CalendarModel
            {
                if (! isset(static::$calendars[$handle])) {
                    return null;
                }

                $calendar = new CalendarModel();
                $calendar->id = crc32($handle);
                $calendar->handle = $handle;
                $calendar->name = static::$calendars[$handle];
                $calendar->hasTitleField = static::$titled;
                $calendar->fieldLayout = static::$layout;

                return $calendar;
            }

            protected static function calendarModels(): array
            {
                $models = [];

                foreach (array_keys(static::$calendars) as $handle) {
                    $models[crc32($handle)] = (new static())->exposeCalendar($handle);
                }

                return $models;
            }

            public function exposeCalendar(string $handle): CalendarModel
            {
                return $this->calendarByHandle($handle);
            }

            protected function authorMatchOptions(): array
            {
                return [];
            }
        };
        $target::$calendars = $calendars;
        $target::$titled = $titled;
        $target::$layout = $layout;

        return $target;
    }

    /**
     * The target's native declaration for a given (or unresolved) calendar.
     *
     * @return list<string>
     */
    protected function nativeHandles(?CalendarModel $calendar): array
    {
        /** @var object $target */
        $target = $this->targetWithCalendars([]);

        return array_map(
            static fn(MappableField $field): string => $field->handle,
            $target->natives($calendar),
        );
    }

    /** Apply a date native from a feed carrying it under `when`, with an explicit format. */
    protected function applyDate(Event $event, string $handle, mixed $value): bool
    {
        $target = $this->targetWithCalendars(['events' => 'Events']);

        return $target->applyNativeAttribute(
            $this->context($target),
            $event,
            $handle,
            new RemoteItem($value === null ? [] : ['when' => $value]),
            FieldMapping::fromConfig($handle, ['node' => 'when', 'options' => ['format' => 'Y-m-d H:i:s']]),
        );
    }

    protected function applyAllDay(Event $event, array $feed): bool
    {
        $target = $this->targetWithCalendars(['events' => 'Events']);

        return $target->applyNativeAttribute(
            $this->context($target),
            $event,
            'allDay',
            new RemoteItem($feed),
            FieldMapping::fromConfig('allDay', ['node' => 'whole_day']),
        );
    }
}
