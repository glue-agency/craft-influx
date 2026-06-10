<?php

namespace TDM\Influx\enums;

/**
 * What a sync run should do with one remote item, decided by
 * {@see \TDM\Influx\models\Link::decideAction()}.
 *
 * Create/Update intentionally share strings with the corresponding processing
 * flags since they name the same action; the Skip* values name the reason a
 * sync would not touch the element.
 */
enum SyncDecision: string
{
    case Create = 'create';
    case Update = 'update';
    case SkipNoMatch = 'skip:no-match';
    case SkipNoCreate = 'skip:no-create';
    case SkipNoUpdate = 'skip:no-update';

    public function isSkip(): bool
    {
        return match ($this) {
            self::Create, self::Update => false,
            default => true,
        };
    }

    /**
     * Human-readable reason for the skip outcomes whose message doesn't
     * depend on item data. SkipNoMatch messages are built by the caller —
     * they embed the configured match node — and Create/Update have none.
     */
    public function skipReason(): ?string
    {
        return match ($this) {
            self::SkipNoCreate => "No existing element and 'create' not enabled.",
            self::SkipNoUpdate => "'update' not enabled for this link.",
            default => null,
        };
    }
}
