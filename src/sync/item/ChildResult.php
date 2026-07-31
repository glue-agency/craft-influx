<?php

namespace GlueAgency\Influx\sync\item;

use craft\base\ElementInterface;

/**
 * One nested element of a mapping row — a Matrix block, or a related element the
 * mapping's sub-fields wrote. Produced by the same walk that produces the
 * {@see MappingResult}s, for the real run and the dry-run alike, so the
 * inspectors' drill-down is by construction what the sync would do.
 *
 * Values are raw (un-truncated, un-stringified); presentation belongs to the
 * consumer. Treat as read-only.
 */
class ChildResult
{
    /**
     * Display title for the child row: the block type's display name (Matrix),
     * or the related element's UI label.
     */
    public ?string $title = null;

    /** The Matrix block type's handle; null for relation/asset children. */
    public ?string $blockType = null;

    /**
     * The persisted related element this child stands for (relation/asset
     * children). Null for a Matrix block — a block is not a navigable identity
     * in the drill-down.
     */
    public ?ElementInterface $element = null;

    /**
     * Layout source for resolving the child row's field labels and display
     * normalization at presentation time: a throwaway block element, the current
     * block, or {@see $element}. Never presented as identity.
     */
    public ?ElementInterface $labelElement = null;

    /**
     * The child's final action, resolved at build time — the dry-run label in
     * the debug walk, the committed value in a real run. Values come from
     * {@see \GlueAgency\Influx\enums\ChildAction}.
     */
    public string $action = '';

    /**
     * The child's own field rows. Recursive by construction: a row may itself
     * carry children ({@see MappingResult::$children}).
     *
     * @var list<MappingResult>
     */
    public array $mappingResults = [];

    /**
     * @param list<MappingResult> $mappingResults
     */
    public function __construct(
        ?string $title = null,
        ?string $blockType = null,
        ?ElementInterface $element = null,
        ?ElementInterface $labelElement = null,
        string $action = '',
        array $mappingResults = [],
    ) {
        $this->title = $title;
        $this->blockType = $blockType;
        $this->element = $element;
        $this->labelElement = $labelElement;
        $this->action = $action;
        $this->mappingResults = $mappingResults;
    }
}
