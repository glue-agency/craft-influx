<?php

namespace GlueAgency\Influx\enums;

/**
 * Outcome of processing one remote item. Stored verbatim on log-item rows
 * (`action` column) and read back by the logs UI, so the backed values must
 * stay stable.
 */
enum ItemAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Error = 'error';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
    case DeletedForSite = 'deleted-for-site';

    /**
     * The log counter column this action increments, or null when the action
     * isn't counted separately (errors only bump `itemsSeen`).
     */
    public function counterAttribute(): ?string
    {
        return match ($this) {
            self::Created   => 'itemsCreated',
            self::Updated   => 'itemsUpdated',
            self::Unchanged => 'itemsUnchanged',
            self::Skipped   => 'itemsSkipped',
            self::Disabled, self::Deleted, self::DeletedForSite => 'itemsDeleted',
            self::Error => null,
        };
    }

    /**
     * The label the dry-run debug view shows for this action. Errors stay
     * 'error' — a dry run can't soften those. The strings are part of the
     * debug view's JS/Twig contract; don't reword without updating it.
     */
    public function dryRunLabel(): string
    {
        return match ($this) {
            self::Created        => 'would-create',
            self::Updated        => 'would-update',
            self::Unchanged      => 'would-unchanged',
            self::Skipped        => 'would-skip',
            self::Error          => 'error',
            self::Disabled       => 'would-disable',
            self::Deleted        => 'would-delete',
            self::DeletedForSite => 'would-delete-for-site',
        };
    }
}
