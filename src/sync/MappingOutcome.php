<?php

namespace GlueAgency\Influx\sync;

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

    /**
     * "handle: message" for every row whose strategy threw. Used to surface
     * field failures on the item's action/message — a field that errors never
     * counts as a change, so without this an item whose only mapping failed
     * would be logged as "unchanged" and hide the failure.
     *
     * @return string[]
     */
    public function errorMessages(): array
    {
        $messages = [];

        foreach ($this->results as $result) {
            if ($result->error !== null) {
                $messages[] = "{$result->handle}: {$result->error}";
            }
        }

        return $messages;
    }
}
