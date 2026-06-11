<?php

namespace GlueAgency\Influx\sync;

use craft\base\ElementInterface;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\models\Link;

/**
 * Output of {@see ItemProcessor::resolve()} — what one remote item matched
 * and what the run intends to do about it. Treat as read-only; the populate
 * phase consumes it, and element swaps go through {@see withElement()}.
 */
class ItemResolution
{
    public mixed $matchValue = null;

    public ?ElementInterface $element = null;

    public SyncDecision $decision;

    public function __construct(mixed $matchValue, ?ElementInterface $element, SyncDecision $decision)
    {
        $this->matchValue = $matchValue;
        $this->element = $element;
        $this->decision = $decision;
    }

    /**
     * Swap in a different element (beforeItem listeners may) and re-derive
     * the decision — a listener handing us an element turns a no-create skip
     * into an update, and vice versa.
     */
    public function withElement(Link $link, ?ElementInterface $element): self
    {
        return new self(
            $this->matchValue,
            $element,
            $link->decideAction($this->matchValue, $element),
        );
    }
}
