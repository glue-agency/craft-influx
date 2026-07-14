<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;

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

    /**
     * The handles of the mappings that actually changed this run — the
     * run-time record the log drill-down persists, since its dry-run
     * re-inspection reads present-tense (an item that updated successfully
     * shows "no change" on every row once its element already carries the new
     * values). A field that threw never counts as changed ({@see
     * MappingResult::$changed} stays null/false), mirroring the aggregate
     * {@see ItemProcessor::populate()} folds.
     *
     * Null when there are no mapping results at all — a sweep row, or an item
     * that errored before populate() ran. That "unknown" is deliberately
     * distinct from an empty list's "compared, nothing changed".
     *
     * @return list<string>|null
     */
    public function changedFieldHandles(): ?array
    {
        if ($this->mappingResults === []) {
            return null;
        }

        $handles = [];

        foreach ($this->mappingResults as $result) {
            if ($result->changed === true) {
                $handles[] = $result->handle;
            }
        }

        return $handles;
    }
}
