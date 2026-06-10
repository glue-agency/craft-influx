<?php

namespace TDM\Influx\sync;

/**
 * What {@see MappingApplier::apply()} did to one element: the aggregate
 * changed flag the save decision hangs on, plus one {@see MappingResult}
 * per mapping for logging and the debug view. Treat as read-only.
 */
class MappingOutcome
{
    public bool $changed = false;

    /** @var list<MappingResult> */
    public array $results = [];

    /** @param list<MappingResult> $results */
    public function __construct(bool $changed, array $results)
    {
        $this->changed = $changed;
        $this->results = $results;
    }
}
