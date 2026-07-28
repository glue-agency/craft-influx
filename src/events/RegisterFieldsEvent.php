<?php

namespace GlueAgency\Influx\events;

/**
 * Fired once, lazily, when the field-strategy registry is first asked for
 * its registered classes. Listeners can append, override, or filter
 * {@see \GlueAgency\Influx\fields\Field} subclasses.
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
class RegisterFieldsEvent extends RegisterEvent
{
    /** @var class-string<\GlueAgency\Influx\fields\Field>[] */
    public array $fields = [];

    public function seed(array $classes): void
    {
        $this->fields = $classes;
    }

    public function registered(): array
    {
        return $this->fields;
    }
}
