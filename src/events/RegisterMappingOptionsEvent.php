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
 */
class RegisterMappingOptionsEvent extends Event
{
    /** @var list<array{value: string, label: string}> */
    public array $options = [];
}
