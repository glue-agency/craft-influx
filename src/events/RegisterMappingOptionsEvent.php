<?php

namespace GlueAgency\Influx\events;

use yii\base\Event;

/**
 * Carries a UI-facing option list from a {@see \GlueAgency\Influx\fields\Field}
 * strategy to its listeners, which may append, replace, or filter the default
 * set before it reaches the mapping editor.
 *
 * Currently the date-format presets ({@see \GlueAgency\Influx\fields\Date::formatOptions()})
 * are the only producer; other strategies may adopt it. Not every option list
 * is extensible — {@see \GlueAgency\Influx\fields\Assets}' mode / conflict
 * dropdowns are deliberately closed, since each value maps to a fixed parse()
 * branch.
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
