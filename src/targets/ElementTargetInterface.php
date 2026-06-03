<?php

namespace TDM\Influx\targets;

use craft\base\ElementInterface;
use TDM\Influx\models\Feed;

/**
 * Adapter that lets the sync engine talk to any element type. One
 * implementation per element type (Entry, Calendar Event, Commerce Product,
 * ...). Built-ins are registered by Influx; third-party targets register via
 * TargetsService::register().
 *
 * Each target owns three concerns:
 *   1. Decide whether it can handle a given feed (typically by FQCN match).
 *   2. Find an existing element for a feed item, or build a new one.
 *   3. Apply feed-level requirements (section/type/calendar/etc.) so a freshly
 *      built element passes Craft's save-time validation.
 */
interface ElementTargetInterface
{
    /**
     * FQCN of the element class this target handles, e.g. craft\elements\Entry.
     */
    public static function elementType(): string;

    /**
     * Is this target the right one for the given feed? Default impl in
     * AbstractElementTarget matches on $feed->elementType.
     */
    public function handles(Feed $feed): bool;

    /**
     * Does this feed claim this element? Used by the "Sync from remote" button
     * to decide whether to show, and by the per-element sync action to look up
     * the right feed for an element. Usually: same elementType, same section,
     * and the element has a match-attribute value.
     */
    public function claimsElement(Feed $feed, ElementInterface $element): bool;

    /**
     * Find an existing element matching the given key value, or null. The
     * implementation is expected to query *across sites* (status=null,
     * site=*) — multi-site feeds rely on finding the same canonical element
     * regardless of which site is being processed first.
     */
    public function findByMatchValue(Feed $feed, mixed $matchValue, ?int $siteId = null): ?ElementInterface;

    /**
     * Build a fresh element pre-populated with all of the feed-mandated
     * attributes (section, type, ...) so the caller can apply mappings and
     * save without further setup.
     */
    public function buildNew(Feed $feed, ?int $siteId = null): ElementInterface;

    /**
     * Soft-disable an element (set status to disabled). For elements that
     * don't support disabling, this is a no-op.
     */
    public function disable(ElementInterface $element): bool;

    /**
     * Hard-delete an element from all sites.
     */
    public function delete(ElementInterface $element): bool;

    /**
     * Delete the element from a single site only.
     */
    public function deleteForSite(ElementInterface $element, int $siteId): bool;
}
