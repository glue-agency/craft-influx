<?php

namespace TDM\Influx\targets;

use craft\base\ElementInterface;
use TDM\Influx\models\Link;

/**
 * Adapter that lets the sync engine talk to any element type. One
 * implementation per element type (Entry, Calendar Event, Commerce Product,
 * ...). Built-ins are registered by Influx; third-party targets register via
 * TargetsService::register().
 *
 * Each target owns three concerns:
 *   1. Decide whether it can handle a given link (typically by FQCN match).
 *   2. Find an existing element for a link item, or build a new one.
 *   3. Apply link-level requirements (section/type/calendar/etc.) so a freshly
 *      built element passes Craft's save-time validation.
 */
interface ElementTargetInterface
{
    /**
     * FQCN of the element class this target handles, e.g. craft\elements\Entry.
     */
    public static function elementType(): string;

    /**
     * Is this target the right one for the given link?
     */
    public function handles(Link $link): bool;

    /**
     * Does this link claim this element? Used by the "Sync from remote"
     * button to decide whether to show, and by the per-element sync action
     * to look up the right link for an element.
     */
    public function claimsElement(Link $link, ElementInterface $element): bool;

    /**
     * Find an existing element matching the given key value, or null.
     * Implementations are expected to query across sites — multi-site links
     * rely on finding the same canonical element regardless of which site is
     * being processed first.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface;

    /**
     * Build a fresh element pre-populated with all link-mandated attributes
     * so the caller can apply mappings and save without further setup.
     */
    public function buildNew(Link $link, ?int $siteId = null): ElementInterface;

    public function disable(ElementInterface $element): bool;

    public function delete(ElementInterface $element): bool;

    public function deleteForSite(ElementInterface $element, int $siteId): bool;
}
