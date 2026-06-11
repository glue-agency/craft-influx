<?php

namespace GlueAgency\Influx\events;

use yii\base\Event;

/**
 * Fired by every static method on a {@see \GlueAgency\Influx\fields\Field} strategy
 * that exposes a UI-facing option list — the date-format presets, the asset
 * mode / conflict dropdowns, etc. Listeners receive the default set and may
 * append, replace, or filter it before it reaches the mapping editor.
 *
 *   Event::on(
 *       \GlueAgency\Influx\fields\Date::class,
 *       \GlueAgency\Influx\fields\Date::EVENT_REGISTER_FORMAT_OPTIONS,
 *       function (RegisterMappingOptionsEvent $event) {
 *           $event->options[] = ['value' => 'd.m.Y', 'label' => 'DE date'];
 *       }
 *   );
 *
 * Each entry is `['value' => string, 'label' => string]`. Strategies use
 * the same event class for every list so a third-party plugin can register
 * one handler per option set without learning a new event shape each time.
 */
class RegisterMappingOptionsEvent extends Event
{
    /** @var list<array{value: string, label: string}> */
    public array $options = [];
}
