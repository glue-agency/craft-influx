<?php

namespace GlueAgency\Influx\enums;

/**
 * Outcome of processing one remote item. Stored verbatim on log-item rows
 * (`action` column) and read back by the logs UI, so the backed values must
 * stay stable.
 */
enum ItemAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
    case SKIPPED = 'skipped';
    case ERROR = 'error';
    case DISABLED = 'disabled';
    case DISABLED_FOR_SITE = 'disabled-for-site';
    case DELETED = 'deleted';
    case DELETED_FOR_SITE = 'deleted-for-site';

    /**
     * The log counter column this action increments, or null when the action
     * isn't counted separately (errors only bump `itemsSeen`).
     */
    public function counterAttribute(): ?string
    {
        return match ($this) {
            self::CREATED   => 'itemsCreated',
            self::UPDATED   => 'itemsUpdated',
            self::UNCHANGED => 'itemsUnchanged',
            self::SKIPPED   => 'itemsSkipped',
            self::DISABLED, self::DISABLED_FOR_SITE => 'itemsDisabled',
            self::DELETED, self::DELETED_FOR_SITE => 'itemsDeleted',
            self::ERROR => null,
        };
    }

    /**
     * The label the dry-run debug view shows for this action. Errors stay
     * 'error' — a dry run can't soften those, and UNCHANGED keeps its plain
     * 'unchanged' label: there's no hypothetical write to prefix with 'would-'
     * (the item was fully compared and already matches). The strings are part
     * of the debug view's JS/Twig contract; don't reword without updating it.
     */
    public function dryRunLabel(): string
    {
        return match ($this) {
            self::CREATED           => 'would-create',
            self::UPDATED           => 'would-update',
            self::UNCHANGED         => 'unchanged',
            self::SKIPPED           => 'would-skip',
            self::ERROR             => 'error',
            self::DISABLED          => 'would-disable',
            self::DISABLED_FOR_SITE => 'would-disable-for-site',
            self::DELETED           => 'would-delete',
            self::DELETED_FOR_SITE  => 'would-delete-for-site',
        };
    }
}
