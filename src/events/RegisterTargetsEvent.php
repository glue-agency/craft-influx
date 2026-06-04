<?php

namespace TDM\Influx\events;

use yii\base\Event;

/**
 * Fired once, lazily, when the targets registry is first asked for its
 * registered targets. Listeners can append new {@see \TDM\Influx\targets\ElementTargetInterface}
 * implementations, replace built-ins (by re-adding under the same element type),
 * or remove them entirely by filtering the array.
 *
 *   Event::on(
 *       TargetsService::class,
 *       TargetsService::EVENT_REGISTER_TARGETS,
 *       function (RegisterTargetsEvent $event) {
 *           $event->targets[] = MyCalendarEventTarget::class;
 *       }
 *   );
 */
class RegisterTargetsEvent extends Event
{
    /** @var class-string<\TDM\Influx\targets\ElementTargetInterface>[] */
    public array $targets = [];
}
