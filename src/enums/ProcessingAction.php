<?php

namespace GlueAgency\Influx\enums;

use Craft;
use GlueAgency\Influx\targets\ElementTargetInterface;

/**
 * What a sync run is allowed to do with the elements a link manages. Stored
 * verbatim in the link's `processing` array — a Project Config key and an
 * `influx_links` column — so the backed values must stay stable.
 *
 * THE owner of the processing vocabulary: the value set and its order, the
 * default a new link starts on, the global ⇄ per-site pairing, the builder's
 * labels + notes, and the overview's pill colour.
 */
enum ProcessingAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DISABLE = 'disable';
    case DISABLE_FOR_SITE = 'disable-for-site';
    case DELETE = 'delete';
    case DELETE_FOR_SITE = 'delete-for-site';

    /**
     * Every value in canonical order — the validation range, and the order the
     * Links overview renders its pills in so they read the same regardless of
     * the order they were configured in.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }

    /**
     * What a link processes unless told otherwise: the two non-destructive
     * writes. THE one owner of that default — the model, the builder bootstrap,
     * the edit screen and the Feed Me import all start from here.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [self::CREATE->value, self::UPDATE->value];
    }

    /**
     * Cases in the builder's checkbox order: the writes plus the global
     * missing-element policies first, then their per-site counterparts, so each
     * group reads together. Derived from the canonical order by partitioning on
     * {@see isForSite()} rather than listed a second time.
     *
     * @return list<self>
     */
    public static function optionOrder(): array
    {
        $global = [];
        $forSite = [];

        foreach (self::cases() as $case) {
            if ($case->isForSite()) {
                $forSite[] = $case;
            } else {
                $global[] = $case;
            }
        }

        return array_merge($global, $forSite);
    }

    /**
     * Whether this policy acts on one site's rows rather than on the canonical
     * element across every site.
     */
    public function isForSite(): bool
    {
        return $this->globalCounterpart() !== null;
    }

    /**
     * Whether this policy is a missing-elements sweep rather than a write — the
     * four disable/delete cases, which only apply to an element type whose target
     * can enumerate what a link owns
     * ({@see ElementTargetInterface::supportsSweeping()}). The builder hides these
     * checkboxes for a type that can't sweep, and a save prunes them from stored
     * config ({@see unsupportedReason()}).
     *
     * Derived from the global ⇄ per-site pairing rather than listed again: having
     * a counterpart in either direction is exactly what makes a case a
     * missing-elements policy — the writes have neither.
     */
    public function isMissingPolicy(): bool
    {
        return $this->isForSite() || $this->siteCounterpart() !== null;
    }

    /**
     * The per-site counterpart of a global missing-element policy, or null when
     * there is none (the writes, and the per-site policies themselves).
     *
     * With site-specific endpoints a run owns one site's rows, so a global
     * disable/delete off that site's feed would reach across sites —
     * {@see \GlueAgency\Influx\models\Link::migrateProcessingForEndpointShape()}
     * swaps each pair to match the link's endpoint shape on save.
     */
    public function siteCounterpart(): ?self
    {
        return match ($this) {
            self::DISABLE => self::DISABLE_FOR_SITE,
            self::DELETE  => self::DELETE_FOR_SITE,
            default       => null,
        };
    }

    /**
     * The inverse of {@see siteCounterpart()}: the global policy a per-site one
     * narrows, or null when this isn't a per-site policy.
     */
    public function globalCounterpart(): ?self
    {
        return match ($this) {
            self::DISABLE_FOR_SITE => self::DISABLE,
            self::DELETE_FOR_SITE  => self::DELETE,
            default                => null,
        };
    }

    /**
     * Why a target can't honour this policy, or null when it can — the single
     * gate deciding what {@see \GlueAgency\Influx\models\Link::pruneProcessingForTarget()}
     * drops, and the sentence both surfaces that report a drop print (the
     * builder's save notice and the Feed Me importer's warnings), so classifying
     * and explaining can't drift apart.
     *
     * Only the two capabilities that gate a policy are consulted.
     * `supportsMultiSite()` deliberately isn't: a non-multi-site target already
     * rejects site-specific endpoints, and {@see \GlueAgency\Influx\models\Link::migrateProcessingForEndpointShape()}
     * folds the `-for-site` pair back onto its global counterpart for a link
     * without them — pruning there would report a drop where a swap is what
     * actually happens.
     */
    public function unsupportedReason(ElementTargetInterface $target): ?string
    {
        if ($this === self::CREATE && ! $target::supportsCreating()) {
            return Craft::t('influx', '{elementType} links can’t create elements.', [
                'elementType' => $target::friendlyName(),
            ]);
        }

        if ($this->isMissingPolicy() && ! $target::supportsSweeping()) {
            return Craft::t('influx', '{elementType} links can’t tell which elements are missing from the feed.', [
                'elementType' => $target::friendlyName(),
            ]);
        }

        return null;
    }

    /**
     * Terse label for the builder's checkbox and the migration notice.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE           => Craft::t('influx', 'Create'),
            self::UPDATE           => Craft::t('influx', 'Update'),
            self::DISABLE          => Craft::t('influx', 'Disable globally'),
            self::DISABLE_FOR_SITE => Craft::t('influx', 'Disable for site'),
            self::DELETE           => Craft::t('influx', 'Delete globally'),
            self::DELETE_FOR_SITE  => Craft::t('influx', 'Delete for site'),
        };
    }

    /**
     * The behaviour spelled out, shown beneath the builder's checkbox label.
     */
    public function note(): string
    {
        return match ($this) {
            self::CREATE           => Craft::t('influx', 'Adds elements from the feed that don’t exist in Craft yet.'),
            self::UPDATE           => Craft::t('influx', 'Writes feed changes onto elements that already exist.'),
            self::DISABLE          => Craft::t('influx', 'When an element is missing from the feed, disables it across all sites.'),
            self::DISABLE_FOR_SITE => Craft::t('influx', 'When an element is missing from a site’s feed, disables just that site’s element.'),
            self::DELETE           => Craft::t('influx', 'When an element is missing from the feed, deletes it across all sites.'),
            self::DELETE_FOR_SITE  => Craft::t('influx', 'When an element is missing from a site’s feed, deletes just that site’s element.'),
        };
    }

    /**
     * Pill colour on the Links overview — the configuration palette, where
     * `create` reads as informative rather than as a write: create = blue,
     * update = green, disable = gray, delete = red.
     */
    public function color(): string
    {
        return match ($this) {
            self::CREATE => 'blue',
            self::UPDATE => 'green',
            self::DISABLE, self::DISABLE_FOR_SITE => 'gray',
            self::DELETE, self::DELETE_FOR_SITE => 'red',
        };
    }
}
