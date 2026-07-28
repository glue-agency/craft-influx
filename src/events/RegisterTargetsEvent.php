<?php

namespace GlueAgency\Influx\events;

/**
 * Fired once, lazily, when the targets registry is first asked for its
 * registered targets. Listeners can append new {@see \GlueAgency\Influx\targets\ElementTargetInterface}
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
class RegisterTargetsEvent extends RegisterEvent
{
    /** @var class-string<\GlueAgency\Influx\targets\ElementTargetInterface>[] */
    public array $targets = [];

    public function seed(array $classes): void
    {
        $this->targets = $classes;
    }

    public function registered(): array
    {
        return $this->targets;
    }
}
