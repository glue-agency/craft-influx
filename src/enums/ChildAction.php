<?php

namespace GlueAgency\Influx\enums;

/**
 * Outcome for one nested element of an item: a Matrix block, or a related
 * element written through a sub-mapping. Shown on the drill-down rows of both
 * inspectors — as a {@see dryRunLabel()} variant in the debug view, as the
 * committed value in the run log — so the backed values must stay stable.
 *
 * ADDED / REMOVED are a child appearing in or dropping out of the parent
 * field's rebuilt value (Matrix full-replace semantics); CREATED / UPDATED /
 * UNCHANGED are a related element the sync wrote (or didn't) through the
 * mapping's sub-fields.
 */
enum ChildAction: string
{
    case ADDED = 'added';
    case REMOVED = 'removed';
    case CREATED = 'created';
    case UPDATED = 'updated';
    case UNCHANGED = 'unchanged';
    case ERROR = 'error';

    /**
     * The label the dry-run debug view shows for this action. Errors stay
     * 'error' — a dry run can't soften those — and UNCHANGED keeps its plain
     * 'unchanged' label: there's no hypothetical write to prefix with 'would-'
     * (the child was fully compared and already matches). The strings are part
     * of the debug view's JS/Twig contract; don't reword without updating it.
     */
    public function dryRunLabel(): string
    {
        return match ($this) {
            self::ADDED     => 'would-add',
            self::REMOVED   => 'would-remove',
            self::CREATED   => 'would-create',
            self::UPDATED   => 'would-update',
            self::UNCHANGED => 'unchanged',
            self::ERROR     => 'error',
        };
    }

    /**
     * Craft status colour for the action badge — `live` (wrote), `pending`
     * (neutral), `expired` (destructive / failed). Deliberately the same palette
     * as {@see ItemAction::color()}: the values the two enums share must badge
     * identically whether the row is an item or one of its children, and both
     * reach the Vue apps through the one map
     * {@see \GlueAgency\Influx\web\Vocabulary} ships.
     */
    public function color(): string
    {
        return match ($this) {
            self::ADDED, self::CREATED, self::UPDATED => 'live',
            self::UNCHANGED => 'pending',
            self::REMOVED, self::ERROR => 'expired',
        };
    }
}
