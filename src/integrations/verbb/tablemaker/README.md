# Table Maker

Mapping strategy for [verbb/tablemaker](https://github.com/verbb/tablemaker).

## Why this one is different

Craft's own Table field defines its columns in the FIELD SETTINGS, so Influx can
offer one mapping row per column and be sure those columns exist. Table Maker's
whole premise is the opposite: an entry defines its own columns and its own
values, however many or few it wants.

That rules out declaring the columns in the mapping — doing so would turn a
per-entry structure into a fixed one for every item the feed carries. So the row
takes **one source node holding the whole table**, and the columns arrive from the
feed with the values.

The cost is that the feed has to speak a fixed shape. It's below.

## Feed format

Map the row's source node at an object with `columns` and `values`:

```json
{
    "table": {
        "columns": ["this", "that", "other"],
        "values": [
            [1, 2, 3],
            [4, 5, 6],
            [null, 7, 8],
            ["pizza", "sausage"]
        ]
    }
}
```

### Columns

A column is either a **string** — its label — or an **object** with `label` plus
any of `type`, `align` and `width`. Only `label` is required, and the two forms
can be mixed in one list.

```json
"columns": [
    { "label": "this",  "width": 50 },
    { "label": "that",  "width": 25, "type": "number" },
    { "label": "other", "width": 25, "align": "right" }
]
```

| Key | Required | Notes |
|---|---|---|
| `label` | yes | The column heading. A column without one is dropped — see below. |
| `type` | no | One of the types below. Defaults to `singleline`. |
| `align` | no | `left`, `center` or `right`. Defaults to the field's own. |
| `width` | no | A number or a string; stored as a string either way. |

Accepted `type` values:

`singleline` · `multiline` · `number` · `checkbox` · `lightswitch` · `color` ·
`date` · `time` · `url` · `email`

An unrecognised type falls back to `singleline`. **`select` is deliberately not
accepted**: a dropdown cell must hold one of a closed set of options that the feed
has no way to declare, so accepting one would store a value the CP can't render
back.

A column with no usable `label` is **dropped**. It would still occupy a position
every row counts against, and a feed shipping one has a bug — keeping it would
silently widen the table with a blank heading.

### Values

`values` is a list of rows, each a list of cells **positional against `columns`**.

- A row **shorter** than the column set leaves the remaining cells empty.
- An explicit `null` **is** an empty cell.
- A row **longer** than the column set has its tail dropped: Table Maker reads a
  cell as `$row[$i]` against `$columns[$i]`, so a cell with no column has nowhere
  to be stored.

Cells are coerced by their column's type before storage. Worth knowing: a
`singleline` or `multiline` column stores TEXT, so a feed shipping `1` into one
gets `"1"` back. Use `type: "number"` to keep it numeric.

### Not a table

A node resolving to something that isn't a table — no `columns`, an empty
`columns`, or not an object at all — **clears the field** rather than failing the
item. The row was addressed, so the feed is authoritative, and an empty table is
what "this item has none" looks like.

## Sync semantics

Full-replace: the feed owns the whole table, columns included.

Change detection compares columns and rows together — a renamed heading IS a
change to the field — with each cell reduced by its column type, because a CP
round-trip stores a checkbox as a real bool and a date as a `DateTime` while the
feed carries `"yes"` and an ISO string.

It never compares `table`, the rendered HTML preview Table Maker regenerates from
the other two keys on every read. Comparing it would report a change on every
single sync.

A Table Maker row reports as one value in the log rather than drilling down per
row, the way an `Addresses` mapping does.

## Installation

Nothing to do. The strategy is keyed by class string
(`verbb\tablemaker\fields\TableMakerField`), so an install without the plugin
never touches it.
