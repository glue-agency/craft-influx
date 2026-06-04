<?php

namespace TDM\Influx\Tests\feature\Support;

use TDM\Influx\events\SyncItemEvent;
use TDM\Influx\events\SyncLinkEvent;
use TDM\Influx\services\SynchronizationService;
use yii\base\Event;

/**
 * Captures every sync-related event in order so tests can assert both the
 * sequence and the per-event payload.
 *
 *   $recorder = EventRecorder::attach();
 *   ... run sync ...
 *   $this->assertSame(
 *       [
 *           'beforeSyncLink',
 *           'beforeItem',
 *           'afterItemMapping',
 *           'afterItem',
 *           'afterSyncLink',
 *       ],
 *       $recorder->names(),
 *   );
 */
final class EventRecorder
{
    /** @var list<array{name:string, event:Event}> */
    public array $events = [];

    public static function attach(): self
    {
        $self = new self();

        $eventNames = [
            SynchronizationService::EVENT_BEFORE_SYNC_LINK,
            SynchronizationService::EVENT_AFTER_SYNC_LINK,
            SynchronizationService::EVENT_BEFORE_ITEM,
            SynchronizationService::EVENT_AFTER_ITEM_MAPPING,
            SynchronizationService::EVENT_AFTER_ITEM,
        ];

        foreach ($eventNames as $name) {
            Event::on(
                SynchronizationService::class,
                $name,
                function (Event $event) use ($self, $name) {
                    $self->events[] = ['name' => $name, 'event' => $event];
                },
            );
        }

        return $self;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(array $e) => $e['name'], $this->events);
    }

    /** @return list<Event> */
    public function payloads(string $name): array
    {
        return array_values(array_filter(array_map(
            static fn(array $e) => $e['event'],
            array_filter($this->events, static fn(array $e) => $e['name'] === $name),
        )));
    }

    public function actions(): array
    {
        return array_map(
            static fn(Event $e) => $e instanceof SyncItemEvent ? $e->action : null,
            $this->payloads(SynchronizationService::EVENT_AFTER_ITEM),
        );
    }
}
