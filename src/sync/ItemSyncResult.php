<?php

namespace TDM\Influx\sync;

use craft\base\ElementInterface;
use TDM\Influx\enums\ItemAction;
use TDM\Influx\enums\SyncDecision;

/**
 * Everything that happened (or, on a dry run, would happen) to one remote
 * item. Built by {@see ItemProcessor::populate()} and finalized by
 * {@see ItemProcessor::commit()}. Treat as read-only.
 */
class ItemSyncResult
{
    public SyncDecision $decision;

    public ItemAction $action;

    public mixed $matchValue = null;

    public ?ElementInterface $element = null;

    public bool $isNew = false;

    public bool $changed = false;

    /** @var list<MappingResult> */
    public array $mappingResults = [];

    /** Skip reason or save-failure detail, when there is one. */
    public ?string $message = null;

    /** @param list<MappingResult> $mappingResults */
    public function __construct(
        SyncDecision $decision,
        ItemAction $action,
        mixed $matchValue,
        ?ElementInterface $element,
        bool $isNew,
        bool $changed,
        array $mappingResults = [],
        ?string $message = null,
    ) {
        $this->decision = $decision;
        $this->action = $action;
        $this->matchValue = $matchValue;
        $this->element = $element;
        $this->isNew = $isNew;
        $this->changed = $changed;
        $this->mappingResults = $mappingResults;
        $this->message = $message;
    }

    /**
     * Per-mapping errors captured by the applier, keyed by mapping handle.
     * Empty when every mapping parsed cleanly.
     *
     * @return array<string, string>
     */
    public function mappingErrors(): array
    {
        $errors = [];
        foreach ($this->mappingResults as $result) {
            if ($result->error !== null) {
                $errors[$result->handle] = $result->error;
            }
        }
        return $errors;
    }
}
