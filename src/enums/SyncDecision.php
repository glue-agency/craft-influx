<?php

namespace GlueAgency\Influx\enums;

/**
 * What a sync run should do with one remote item, decided by
 * {@see \GlueAgency\Influx\models\Link::decideAction()}.
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
