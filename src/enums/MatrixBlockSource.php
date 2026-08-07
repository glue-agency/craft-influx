<?php

namespace GlueAgency\Influx\enums;

/**
 * Where a {@see \GlueAgency\Influx\fields\Matrix} mapping's blocks come from —
 * the one setting that decides both the feed shape the row reads and whether
 * block ORDER survives the sync. Stored on the mapping as
 * `options.blockSource`, so the backed values must stay stable.
 *
 * GROUPED is the original engine and the default: every child node is ABSOLUTE
 * against the whole item, per-type value lists are index-zipped into blocks,
 * and the block types are emitted in the field's declared order — so a feed
 * ordered text, quote, text, text comes out text, text, text, quote. It's the
 * only source that can build blocks from lists living in UNRELATED parts of the
 * item (a `cast` type reading `actors.*` beside a `crew` type reading
 * `directors.*`), which is why it stays.
 *
 * The three LIST_* sources read ONE list node and emit one block per element in
 * feed order, with child nodes RELATIVE to the element. They differ only in how
 * an element names its block type:
 *
 *   - LIST_SINGLE — it doesn't; every element is the one configured type.
 *   - LIST_BY_KEY — the element's own key does (`{"text": {...}}`), matched
 *     against each type's `sourceKey` option (default: its own handle).
 *   - LIST_BY_NODE — a discriminator node on the element does
 *     (`{"type": "text", ...}`), named by the `typeNode` option and matched
 *     against the same `sourceKey`s.
 *
 * Reading per element is also what keeps blocks of the SAME type aligned:
 * {@see \GlueAgency\Influx\sync\item\RemoteItem::get()} drops nulls when it
 * collapses a list, so under GROUPED a sub-field absent from one block shifts
 * every later value of that sub-field up a block
 * ({@see \GlueAgency\Influx\sync\item\RemoteItem::each()}).
 */
enum MatrixBlockSource: string
{
    case GROUPED = 'grouped';
    case LIST_SINGLE = 'listSingle';
    case LIST_BY_KEY = 'listByKey';
    case LIST_BY_NODE = 'listByNode';

    /**
     * Whether this source reads one list node positionally — the question every
     * branch in the strategy actually asks, so it's asked once here rather than
     * enumerated at each site.
     */
    public function isList(): bool
    {
        return $this !== self::GROUPED;
    }
}
