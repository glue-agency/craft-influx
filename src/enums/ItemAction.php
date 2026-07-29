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
     * One case per distinct log counter, in canonical order: the base of each
     * counter group (a per-site variant shares its base's counter, so it folds
     * into it) with ERROR dropped, having no counter of its own. THE display
     * order for both the overviews' result pills and the log viewer's counter
     * row, so the two can't drift.
     *
     * @return list<self>
     */
    public static function countedCases(): array
    {
        $cases = [];
        $counters = [];

        foreach (self::cases() as $case) {
            $counter = $case->counterAttribute();

            if ($counter === null || isset($counters[$counter])) {
                continue;
            }
            $counters[$counter] = true;
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * The log counter column this action increments, or null when the action has
     * no column of its own — errors are visible as `error` rows and through the
     * nav's error badge instead.
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
     * The action values a filter on this action should match: every case that
     * shares its counter ({@see counterAttribute()}). The UI's filters are the
     * counters, and a counter groups an action with its per-site sibling
     * (`deleted` + `deleted-for-site`, `disabled` + `disabled-for-site`), so the
     * filtered list must group them the same way or it undercounts. Actions
     * with no shared counter (or none — errors) match only themselves.
     *
     * @return string[]
     */
    public function filterGroup(): array
    {
        $counter = $this->counterAttribute();

        if ($counter === null) {
            return [$this->value];
        }

        $group = [];

        foreach (self::cases() as $case) {
            if ($case->counterAttribute() === $counter) {
                $group[] = $case->value;
            }
        }

        return $group;
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

    /**
     * Craft status colour for the action badge — `live` (wrote), `pending`
     * (neutral), `expired` (destructive / failed). The palette the Vue apps
     * render both committed actions and {@see dryRunLabel()} strings with,
     * shipped to them by {@see \GlueAgency\Influx\web\Vocabulary}.
     */
    public function color(): string
    {
        return match ($this) {
            self::CREATED, self::UPDATED => 'live',
            self::UNCHANGED, self::SKIPPED => 'pending',
            self::ERROR, self::DISABLED, self::DISABLED_FOR_SITE, self::DELETED, self::DELETED_FOR_SITE => 'expired',
        };
    }

    /**
     * Colour in the overviews' result-pill palette — green (wrote), gray
     * (neutral / no change), red (destructive / failed).
     *
     * Deliberately NOT {@see color()}: the two are different vocabularies
     * (`.influx-pill--*` versus Craft's status colours) and they disagree on
     * `disabled`, which reads as neutral in a run's result summary but as
     * destructive on the badge of the row that caused it.
     */
    public function pillColor(): string
    {
        return match ($this) {
            self::CREATED, self::UPDATED => 'green',
            self::UNCHANGED, self::SKIPPED, self::DISABLED, self::DISABLED_FOR_SITE => 'gray',
            self::ERROR, self::DELETED, self::DELETED_FOR_SITE => 'red',
        };
    }
}
