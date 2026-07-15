<?php

namespace GlueAgency\Influx\enums;

use Craft;
use craft\base\ElementInterface;
use GlueAgency\Influx\models\Link;

/**
 * What a sync run should do with one remote item, decided by
 * {@see self::decide()}.
 *
 * CREATE/UPDATE intentionally share strings with the corresponding processing
 * flags since they name the same action; the SKIP_* values name the reason a
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
     * Decide what a sync run should do with one remote item given its match
     * value and the element (if any) that was found for it. Used by both
     * {@see \GlueAgency\Influx\services\SynchronizationService::processItem()} for
     * the real run and {@see \GlueAgency\Influx\services\InspectorService::inspectWithTarget()}
     * for the dry-run inspector, so both stay aligned on the rule.
     *
     * Moved here from {@see Link::decideAction()} — it reads the link's
     * {@see Link::PROCESSING_CREATE}/{@see Link::PROCESSING_UPDATE} flags, but
     * the decision itself is the sync engine's concern, not the model's.
     */
    public static function decide(Link $link, mixed $matchValue, ?ElementInterface $element): self
    {
        if ($matchValue === null || $matchValue === '') {
            return self::SKIP_NO_MATCH;
        }

        if ($element === null) {
            if (in_array(Link::PROCESSING_CREATE, $link->processing, true)) {
                return self::CREATE;
            }

            return self::SKIP_NO_CREATE;
        }

        if (in_array(Link::PROCESSING_UPDATE, $link->processing, true)) {
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
     * item; {@see \GlueAgency\Influx\sync\ItemProcessor} overrides SKIP_NO_MATCH
     * with the configured match node.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE         => Craft::t('influx', 'Create'),
            self::UPDATE         => Craft::t('influx', 'Update'),
            self::SKIP_NO_MATCH  => Craft::t('influx', 'Remote item has no match value.'),
            self::SKIP_NO_CREATE => Craft::t('influx', "No existing element and 'create' not enabled for this link."),
            self::SKIP_NO_UPDATE => Craft::t('influx', "'update' not enabled for this link."),
        };
    }
}
