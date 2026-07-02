<?php

namespace GlueAgency\Influx\enums;

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
     * the real run and {@see \GlueAgency\Influx\services\DebugService::debugItem()}
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
     * Human-readable reason for the skip outcomes whose message doesn't
     * depend on item data. SKIP_NO_MATCH messages are built by the caller —
     * they embed the configured match node — and CREATE/UPDATE have none.
     */
    public function skipReason(): ?string
    {
        return match ($this) {
            self::SKIP_NO_CREATE => "No existing element and 'create' not enabled.",
            self::SKIP_NO_UPDATE => "'update' not enabled for this link.",
            default              => null,
        };
    }
}
