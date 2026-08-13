<?php

namespace GlueAgency\Influx\enums;

/**
 * The `craft\fields\Table` column types whose cell needs handling of its own,
 * as {@see \GlueAgency\Influx\fields\TableCells} coerces and compares them. The
 * backed values are Craft's own column-type strings, read off a column's `type`
 * key, so they must stay spelled the way Craft spells them.
 *
 * Only the types that branch are cases: every other column type (color, number,
 * dropdown, url, …) is handed over raw for Craft to normalize, so naming it here
 * would name a case nothing asks about. Cases arrive when a type earns one.
 */
enum TableCellType: string
{
    case CHECKBOX = 'checkbox';
    case LIGHTSWITCH = 'lightswitch';
    case SINGLELINE = 'singleline';
    case MULTILINE = 'multiline';
    case DATE = 'date';
    case TIME = 'time';

    /** Whether the cell is a flag rather than a value. */
    public function isFlag(): bool
    {
        return $this === self::CHECKBOX || $this === self::LIGHTSWITCH;
    }

    /** Whether the cell is free text, and so compares trimmed. */
    public function isText(): bool
    {
        return $this === self::SINGLELINE || $this === self::MULTILINE;
    }

    /** Whether the cell carries a moment, and so compares by instant. */
    public function isInstant(): bool
    {
        return $this === self::DATE || $this === self::TIME;
    }
}
