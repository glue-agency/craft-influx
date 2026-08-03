<?php

namespace GlueAgency\Influx\sync\item;

use craft\base\ElementInterface;

/**
 * One nested child of a mapping row — a Matrix block, a related element the
 * mapping's sub-fields wrote, or a Table row (the one flavour that is no element
 * at all, so it carries neither chip nor title). Produced by the same walk that
 * produces the {@see MappingResult}s, for the real run and the dry-run alike, so
 * the inspectors' drill-down is by construction what the sync would do.
 *
 * Values are raw (un-truncated, un-stringified); presentation belongs to the
 * consumer. Treat as read-only — with one sanctioned exception at the very end
 * of the lifecycle: after a real run has committed the owner element, the
 * strategy that produced a child may back-fill the identity it could not have
 * before the save ({@see \GlueAgency\Influx\fields\Field::attachSavedChildren()},
 * driven by {@see ItemRunner::attachSavedChildren()}), once, just before the
 * snapshot is presented.
 */
class ChildResult
{
    /**
     * Display title for the child row: the nested element's OWN title — the
     * Matrix block's title, or the related element's UI label. Null when it has
     * none, which is the drill-down's cue to label the child by its ordinal
     * ("01", "02", …) instead — always the case for a Table row, whose position
     * IS its identity.
     */
    public ?string $title = null;

    /** The Matrix block type's handle; null for relation/asset children. */
    public ?string $blockType = null;

    /**
     * The real element behind this child, when one exists: the related element a
     * relation child wrote, or the current Matrix block an unchanged/removed
     * child stands for (Craft 5 nested entries, Craft 4 MatrixBlocks — both saved
     * elements). Null for a child the sync would only ADD, which has no saved
     * element at derivation time; the drill-down then heads it with its title (or
     * ordinal) rather than an element chip. On a real run the post-commit
     * back-fill puts the saved block here before the snapshot is presented, so a
     * would-add child in a dry run is the case that genuinely stays element-less.
     */
    public ?ElementInterface $element = null;

    /**
     * Layout source for resolving the child row's field labels and display
     * normalization at presentation time: a throwaway block element, the current
     * block, or {@see $element}. Never presented as identity.
     */
    public ?ElementInterface $labelElement = null;

    /**
     * Row labels the producing strategy supplies itself, handle => label,
     * overriding the layout-derived ones. For a child whose rows aren't layout
     * fields and so can't be named by one: a {@see \GlueAgency\Influx\fields\Table}
     * row's cells are COLUMNS, keyed by column id, and only the field's own
     * column config knows that `col1` is "Label"
     * ({@see \GlueAgency\Influx\web\ItemRowPresenter::presentChildren()}). Null
     * for every other child, which has a layout to read.
     *
     * @var array<string, string>|null
     */
    public ?array $labels = null;

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
     * @param array<string, string>|null $labels
     */
    public function __construct(
        ?string $title = null,
        ?string $blockType = null,
        ?ElementInterface $element = null,
        ?ElementInterface $labelElement = null,
        ?array $labels = null,
        string $action = '',
        array $mappingResults = [],
    ) {
        $this->title = $title;
        $this->blockType = $blockType;
        $this->element = $element;
        $this->labelElement = $labelElement;
        $this->labels = $labels;
        $this->action = $action;
        $this->mappingResults = $mappingResults;
    }
}
