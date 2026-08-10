<?php

/**
 * The three Solspace Calendar classes {@see \GlueAgency\Influx\integrations\solspace\calendar\EventTarget}
 * names, standing in for a package this repo doesn't (and shouldn't) require.
 *
 * They live in Calendar's REAL namespaces, because that's the whole point: the
 * target reaches for `Solspace\Calendar\Elements\Event` by name — `is_a()`,
 * `Event::create()`, `$element->calendarId` — and a stub under any other name
 * would only prove that a seam can be overridden. Nothing autoloads this file
 * (`Solspace\…` matches no PSR-4 prefix here), so it takes effect only where a
 * spec requires it, and a real install always wins by being loaded first.
 *
 * Deliberately NOT faithful reimplementations: each carries the members the
 * target actually touches and nothing else. Anything the target reaches through
 * Calendar's SERVICES goes through the target's own protected seams instead
 * ({@see \GlueAgency\Influx\integrations\solspace\calendar\EventTarget::calendarByHandle()}),
 * so no service stub is needed.
 */

namespace Solspace\Calendar {
    /**
     * The plugin class, only ever asked whether it's loaded. Extending Craft's
     * own Plugin is what makes that answer honest: `getInstance()` is Yii's, which
     * reads `Yii::$app->loadedModules` — null in the bootless suite, so the stub
     * reports the plugin as NOT installed, which is exactly the state the suite
     * runs in and what keeps this file from registering a target in
     * {@see \GlueAgency\Influx\services\TargetsService::defaults()}.
     */
    class Calendar extends \craft\base\Plugin
    {
    }
}

namespace Solspace\Calendar\Models {
    /**
     * The calendar, as the four members the target reads: the handle and name its
     * criteria dropdown is built from, the id its targeting compares, the
     * `hasTitleField` flag its title native is gated on, and the field layout its
     * custom-field descriptors come from.
     */
    class CalendarModel extends \craft\base\Model
    {
        public ?int $id = null;

        public ?string $handle = null;

        public ?string $name = null;

        public ?bool $hasTitleField = null;

        public ?\craft\models\FieldLayout $fieldLayout = null;

        public function getFieldLayout(): ?\craft\models\FieldLayout
        {
            return $this->fieldLayout;
        }
    }
}

namespace Solspace\Calendar\Elements {
    /**
     * The event element. A real `craft\base\Element` so the target's `is_a()`,
     * the shared `parseEnabled()` and the base's attribute dispatch all behave as
     * they would in production; `init()` and `behaviors()` are neutralised for the
     * reason {@see \GlueAgency\Influx\Tests\unit\Support\FakeElement} documents.
     *
     * `create()` RECORDS its arguments instead of seeding an event: what a spec
     * needs to pin is that `buildNew()` goes through the factory at all, and with
     * which site and calendar — the seeding itself is Calendar's behaviour, not
     * Influx's.
     */
    class Event extends \craft\base\Element
    {
        public ?int $calendarId = null;

        public null|array|int|string $authorId = null;

        public mixed $startDate = null;

        public mixed $endDate = null;

        public mixed $postDate = null;

        public ?bool $allDay = null;

        /** The link's match attribute in the specs — a real property, as an external id would be. */
        public mixed $importId = null;

        /** @var list<array{siteId: int|null, calendarId: int|null}> */
        public static array $created = [];

        public static function create(?int $siteId = null, ?int $calendarId = null): self
        {
            self::$created[] = ['siteId' => $siteId, 'calendarId' => $calendarId];

            $event = new self();
            $event->calendarId = $calendarId;
            $event->siteId = $siteId;

            return $event;
        }

        public function init(): void
        {
        }

        public function behaviors(): array
        {
            return [];
        }

        public function getAuthorId(): ?int
        {
            return $this->authorId === null ? null : (int) $this->authorId;
        }
    }
}
