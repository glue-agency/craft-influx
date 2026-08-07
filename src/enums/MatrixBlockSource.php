<?php

namespace GlueAgency\Influx\enums;

/**
 * How a {@see \GlueAgency\Influx\fields\Matrix} mapping reads its blocks out of
 * one list node. Stored on the mapping as `options.blockSource`, so the backed
 * values must stay stable.
 *
 * Every source reads ONE list ({@see \GlueAgency\Influx\sync\item\RemoteItem::each()})
 * and emits one block per element IN FEED ORDER, with child nodes RELATIVE to
 * the element. They differ only in how an element names its block type:
 *
 *   - LIST_BY_KEY — the element's own key does (`{"text": {...}}`), matched
 *     against each type's `sourceKey` option (default: its own handle). The
 *     default source, and the shape Feed Me's own model implies.
 *   - LIST_BY_NODE — a discriminator node on the element does
 *     (`{"type": "text", ...}`), named by the `typeNode` option and matched
 *     against the same `sourceKey`s. What most headless sources emit —
 *     Storyblok's `component`, Sanity's `_type`, GraphQL's `__typename`.
 *   - LIST_SINGLE — nothing does; every element is the one mapped type.
 *
 * There is deliberately no source that reads a list per block type off absolute
 * paths. That was the original engine, and it could build blocks out of
 * unrelated parts of an item — but the field's declared type order decided the
 * output, so the feed had no way to ask for `text, quote, text`, and it emitted
 * `text, text, quote` instead. Reading per element is also the only way blocks
 * of the SAME type stay aligned: a path read collapses a list and drops nulls,
 * so a sub-field missing from one block used to shift every later value of it up
 * a block.
 */
enum MatrixBlockSource: string
{
    case LIST_BY_KEY = 'listByKey';
    case LIST_BY_NODE = 'listByNode';
    case LIST_SINGLE = 'listSingle';

    /**
     * The source a row falls back to — what an unset option means, AND what an
     * unrecognised one reads as: a value stored by a newer version of the plugin
     * must not turn into a destructive reinterpretation of the feed.
     */
    public static function fallback(): self
    {
        return self::LIST_BY_KEY;
    }

    /**
     * Whether this source matches a feed key against each block type's
     * `sourceKey` — true for both of the sources that have a type to name, and
     * the gate on the alias boxes in the builder.
     */
    public function matchesKey(): bool
    {
        return $this !== self::LIST_SINGLE;
    }
}
