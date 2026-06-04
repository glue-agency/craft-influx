<?php

namespace TDM\Influx\events;

use yii\base\Event;

/**
 * Fired once, lazily, when the field-strategy registry is first asked for
 * its registered classes. Listeners can append, override, or filter
 * {@see \TDM\Influx\fields\Field} subclasses.
 *
 * The list is keyed only by Craft field FQCN at registration time — adding
 * a new strategy with the same `craftFieldClass()` as a built-in
 * effectively replaces it.
 *
 *   Event::on(
 *       FieldsService::class,
 *       FieldsService::EVENT_REGISTER_FIELDS,
 *       function (RegisterFieldsEvent $event) {
 *           $event->fields[] = MyMatrixStrategy::class;
 *       }
 *   );
 */
class RegisterFieldsEvent extends Event
{
    /** @var class-string<\TDM\Influx\fields\Field>[] */
    public array $fields = [];
}
