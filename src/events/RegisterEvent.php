<?php

namespace GlueAgency\Influx\events;

use yii\base\Event;

/**
 * Base for the plugin's three registry events — field strategies, element
 * targets and auth strategies. Each subclass names its payload after its own
 * registry (`$fields`, `$targets`, `$authTypes`), because that noun is what
 * listener code reads; this pair of methods is the seam
 * {@see \GlueAgency\Influx\services\AbstractRegistry} uses to seed the
 * built-ins and read the result back without knowing the property name.
 *
 * Listeners never call either method — they mutate the named property
 * directly, as each subclass's snippet shows.
 */
abstract class RegisterEvent extends Event
{
    /**
     * Fill the payload with the registry's built-ins, before listeners run.
     *
     * @param list<class-string> $classes
     */
    abstract public function seed(array $classes): void;

    /**
     * What the listeners left behind — appended, replaced or filtered.
     *
     * @return list<class-string>
     */
    abstract public function registered(): array;
}
