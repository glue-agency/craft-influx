<?php

namespace GlueAgency\Influx\sync\item;

/**
 * What one sub-mapping walk produced ({@see MappingApplier::applySubMappings()}):
 * the per-sub-field rows. Replaces the bare bool the walk used to return, so a
 * caller can both decide persistence ({@see changed()}) and hand the rows to a
 * {@see ChildResult} for the inspectors' drill-down.
 *
 * Treat as read-only.
 */
class SubMappingOutcome
{
    /** @var list<MappingResult> */
    public array $results = [];

    /**
     * @param list<MappingResult> $results
     */
    public function __construct(array $results = [])
    {
        $this->results = $results;
    }

    /**
     * Whether any sub-mapping wrote a differing value — the signal a caller uses
     * to decide whether the related element is worth saving.
     */
    public function changed(): bool
    {
        foreach ($this->results as $result) {
            if ($result->changed === true) {
                return true;
            }
        }

        return false;
    }
}
