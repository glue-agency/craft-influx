<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * Shapes a {@see Link} into what the CP screens display about it — the
 * human-readable labels the Links overview and read-only view templates render
 * (element-type name, target criteria, configured-site names), the "Endpoint" /
 * "Resource" rows the log viewer shows for a run, and the debug screen's selector
 * options.
 *
 * Extracted from the model so {@see Link} stays a plain state object: all of it
 * resolves against Craft (registered targets, sites, elements) at render time,
 * which is a presentation concern, not model state.
 *
 * The log-viewer helpers take the run's primitives (`$elementId`,
 * `$siteHandle`) rather than a record, so the pure ones stay unit-testable
 * without a booted Craft or a DB schema.
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
     * What the link is scoped to, as the overview shows it — "Movies / Feature"
     * for an entry link, a volume or group name for the others. Null when nothing
     * is configured or the element type carries no criteria.
     *
     * Formatting belongs to the target ({@see \GlueAgency\Influx\targets\ElementTargetInterface::criteriaLabel()}),
     * which is the only thing that knows what its own criteria keys mean; this
     * used to read Entry's two constants directly, so every other element type's
     * column was blank.
     */
    public function targetCriteriaLabel(Link $link): ?string
    {
        return Influx::getInstance()->targets->forLink($link)?->criteriaLabel($link);
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

    /**
     * What the log viewer shows in its "Endpoint" row for a run. Returns exactly
     * one of two populated shapes:
     *
     *   - `endpointUrl` set, `endpoints` null — a single URL (a single-element
     *     run's item-endpoint template, a site-scoped run, or an all-sites run
     *     on a link with no per-site endpoints);
     *   - `endpoints` a `[{site, url}]` list, `endpointUrl` null — an all-sites
     *     run on a link that HAS per-site endpoints (the base is never fetched,
     *     so no single URL would be honest).
     *
     * Both null when the link has since been deleted.
     *
     * @param ?int $elementId the resource a single-element run was triggered for
     * @param ?string $siteHandle the site the run was scoped to (null = all)
     * @return array{endpointUrl: ?string, endpoints: ?list<array{site: string, url: string}>}
     */
    public function endpointDisplay(?Link $link, ?int $elementId, ?string $siteHandle): array
    {
        if ($link === null) {
            return ['endpointUrl' => null, 'endpoints' => null];
        }

        if ($elementId !== null) {
            return ['endpointUrl' => $link->itemEndpoint, 'endpoints' => null];
        }

        if ($siteHandle !== null) {
            return ['endpointUrl' => $link->endpointForSite($siteHandle) ?? $link->endpoint, 'endpoints' => null];
        }

        $siteHandles = $link->siteHandles();

        if ($siteHandles === []) {
            return ['endpointUrl' => $link->endpoint, 'endpoints' => null];
        }

        $endpoints = [];

        foreach ($siteHandles as $handle) {
            $url = $link->endpointForSite($handle) ?? $link->endpoint;

            if ($url !== null) {
                $endpoints[] = ['site' => $handle, 'url' => $url];
            }
        }

        return ['endpointUrl' => null, 'endpoints' => $endpoints];
    }

    /**
     * The log viewer's "Resource" row for a single-element run: the element chip
     * for the resource the run was triggered for, or its `#id` when it has since
     * been HARD deleted. Null for whole-feed runs, which have no single resource.
     *
     * A soft-deleted resource still resolves (`trashed(null)`) — the plugin's own
     * deletes are soft — and gets a pill saying where it is, since Craft's chip
     * silently drops the hyperlink for a trashed element but shows nothing that
     * explains why.
     */
    public function resourceDisplay(?Link $link, ?int $elementId): ?string
    {
        if ($elementId === null) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, $link?->elementType, null, ['trashed' => null]);

        if (! $element) {
            return '<span class="light">#' . $elementId . '</span>';
        }

        $chip = (new ItemRowPresenter())->elementChip($element);

        if (! $element->trashed) {
            return $chip;
        }

        // Encoded: translation files are user-supplied.
        return $chip . ' <span class="influx-pill influx-pill--gray">' . Html::encode(Craft::t('influx', 'In trash')) . '</span>';
    }

    /**
     * The debug screen's selector options for the link being inspected: its
     * configured sites (display name per handle), its partial-import preset
     * handles, and every link as a jump target. Request-scoped choices (which
     * site / offset is selected) stay with the controller — this only supplies
     * what there is to choose from.
     *
     * @param Link[] $allLinks
     * @return array{
     *   sites: list<array{handle: string, name: string}>,
     *   offsetHandles: list<string>,
     *   links: list<array{handle: string, name: string, url: string}>,
     * }
     */
    public function debugOptions(Link $link, array $allLinks): array
    {
        return [
            'sites' => array_map(static fn(string $handle): array => [
                'handle' => $handle,
                'name'   => Craft::$app->getSites()->getSiteByHandle($handle)?->name ?? $handle,
            ], $link->siteHandles()),

            'offsetHandles' => array_keys($link->offset ?? []),

            'links' => array_values(array_map(static fn(Link $other): array => [
                'handle' => $other->handle,
                'name'   => $other->name,
                'url'    => UrlHelper::cpUrl('influx/debug', ['link' => $other->handle]),
            ], $allLinks)),
        ];
    }
}
