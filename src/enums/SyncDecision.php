<?php

namespace GlueAgency\Influx\enums;

use Craft;
use craft\base\ElementInterface;
use GlueAgency\Influx\models\Link;

/**
 * What a sync run should do with one remote item, decided by
 * {@see self::decide()}.
 *
 * CREATE/UPDATE intentionally share strings with the corresponding
 * {@see ProcessingAction} cases since they name the same action; the SKIP_* values name the reason a
 * sync would not touch the element.
 */
enum SyncDecision: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case SKIP_NO_MATCH = 'skip:no-match';
    case SKIP_NO_CREATE = 'skip:no-create';
    case SKIP_NO_UPDATE = 'skip:no-update';

    /**
     * A later item on a link that writes ONE element, which an earlier item in the
     * same pass already took. Decided by {@see \GlueAgency\Influx\sync\item\ItemProcessor::resolve()}
     * rather than by {@see decide()}, since it's a fact about the pass rather than
     * about the item.
     *
     * A skip rather than a silent overwrite: without a match value every item
     * resolves to the same element, so processing them all would leave whichever
     * one happened to be last, and report a run of successful updates that hid the
     * mismatch between the feed's shape and the target's.
     */
    case SKIP_SINGLE_ELEMENT_TAKEN = 'skip:single-element-taken';

    /**
     * Decide what a sync run should do with one remote item given its match
     * value and the element (if any) that was found for it. Used by both
     * {@see \GlueAgency\Influx\sync\item\ItemRunner::run()} for
     * the real run and {@see \GlueAgency\Influx\services\InspectorService::inspectWithTarget()}
     * for the dry-run inspector, so both stay aligned on the rule.
     *
     * Lives here rather than on {@see Link}: it reads the link's
     * {@see ProcessingAction::CREATE}/{@see ProcessingAction::UPDATE} flags, but
     * the decision itself is the sync engine's concern, not the model's.
     */
    public static function decide(Link $link, mixed $matchValue, ?ElementInterface $element): self
    {
        // A link whose target resolves its element from criteria alone has no match
        // value by design ({@see Link::requiresMatch()}), so an absent one isn't the
        // "item carries no key" failure this branch names — it's the normal state,
        // and the element (or its absence) decides on its own below.
        if ($link->requiresMatch() && ($matchValue === null || $matchValue === '')) {
            return self::SKIP_NO_MATCH;
        }

        if ($element === null) {
            if ($link->allows(ProcessingAction::CREATE)) {
                return self::CREATE;
            }

            return self::SKIP_NO_CREATE;
        }

        if ($link->allows(ProcessingAction::UPDATE)) {
            return self::UPDATE;
        }

        return self::SKIP_NO_UPDATE;
    }

    public function isSkip(): bool
    {
        return match ($this) {
            self::CREATE, self::UPDATE => false,
            default => true,
        };
    }

    /**
     * Human-readable label for a decision — e.g. the inspector's outcome
     * column. The skip variants double as the message shown on a skipped log
     * item; {@see \GlueAgency\Influx\sync\item\ItemProcessor} overrides SKIP_NO_MATCH
     * with the configured match node.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE                    => Craft::t('influx', 'Create'),
            self::UPDATE                    => Craft::t('influx', 'Update'),
            self::SKIP_NO_MATCH             => Craft::t('influx', 'Remote item has no match value.'),
            self::SKIP_NO_CREATE            => Craft::t('influx', "No existing element and 'create' not enabled for this link."),
            self::SKIP_NO_UPDATE            => Craft::t('influx', "'update' not enabled for this link."),
            self::SKIP_SINGLE_ELEMENT_TAKEN => Craft::t(
                'influx',
                'This link writes a single element, which an earlier feed item already filled.',
            ),
        };
    }
}
