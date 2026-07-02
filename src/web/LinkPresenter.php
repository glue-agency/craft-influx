<?php

namespace GlueAgency\Influx\web;

use Craft;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * Shapes a {@see Link} into the human-readable labels the Links overview and
 * read-only view templates render — element-type name, section/entry-type
 * criteria, and configured-site display names.
 *
 * Extracted from the model so {@see Link} stays a plain state object: these
 * labels resolve against Craft (registered targets, sections, sites) at
 * render time, which is a presentation concern, not model state. Passed to the
 * two Twig templates as `presenter` by {@see \GlueAgency\Influx\controllers\LinksController};
 * the templates are its only consumers.
 */
class LinkPresenter
{
    /**
     * Human-readable label for a link's element type — resolved through the
     * registered target's `friendlyName()`, falling back to the class's short
     * name when no target is registered for it.
     */
    public function elementTypeLabel(Link $link): string
    {
        return Influx::getInstance()->targets->friendlyNameFor($link->elementType);
    }

    /**
     * Section + entry-type display labels for the target — e.g. "Movies /
     * Feature" — resolved from the stored handles so the overview reads like
     * the CP rather than echoing raw handles. Null when no section criteria is
     * configured (the element type carries none, or it isn't set yet). Falls
     * back to the handle when a section/type has since been removed.
     */
    public function targetCriteriaLabel(Link $link): ?string
    {
        $criteria = $link->elementCriteria ?? [];
        $sectionHandle = $criteria['section'] ?? null;

        if (! $sectionHandle) {
            return null;
        }

        $section = Compat::getSectionByHandle($sectionHandle);
        $parts = [$section?->name ?? $sectionHandle];

        $typeHandle = $criteria['type'] ?? null;

        if ($typeHandle) {
            $typeName = null;

            if ($section) {
                foreach ($section->getEntryTypes() as $type) {
                    if ($type->handle === $typeHandle) {
                        $typeName = $type->name;

                        break;
                    }
                }
            }

            $parts[] = $typeName ?? $typeHandle;
        }

        return implode(' / ', $parts);
    }

    /**
     * Display names for a link's configured sites, for the overview — falls
     * back to the handle when a site has since been removed.
     *
     * @return string[]
     */
    public function siteLabels(Link $link): array
    {
        return array_map(
            static fn(string $handle): string => Craft::$app->getSites()->getSiteByHandle($handle)?->name ?? $handle,
            $link->siteHandles(),
        );
    }
}
