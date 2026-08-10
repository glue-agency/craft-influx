<?php

namespace GlueAgency\Influx\web;

use Craft;
use craft\base\ElementInterface;
use craft\web\View;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * The entry-edit "Sync from remote" affordance: which links get offered for an
 * element, whether each is currently syncable, and the markup for the resulting
 * button / menu.
 *
 * Extracted from {@see \GlueAgency\Influx\Influx::registerEntrySyncButton()}, which
 * keeps only the `Event::on` wiring — the offering rules (a resource endpoint is
 * required, a missing match value or an active cool-down disables rather than
 * hides, a site is pinned only for per-site endpoints) are display decisions and
 * live here, unit-testable via the two lookup seams below.
 *
 * Markup is built by a CP template (`influx/_sync-button`) rather than
 * concatenated here, so the branching and styling stay in one readable place.
 *
 * That template must not output a `<form>` of its own: additional buttons render
 * INSIDE the edit page's `#main-form`, forms can't nest, and the browser would
 * close `#main-form` early — every field input plus Craft's hidden
 * action/redirect inputs would fall outside it, silently breaking entry saving
 * and drafts. Each button uses Craft's `formsubmit` pattern instead, with
 * `form: false` so the CP JS posts through a detached temporary form (CSRF
 * included) rather than hijacking the closest one.
 */
class SyncButtonPresenter
{
    /**
     * The affordance's HTML, or null when the element has nothing to offer —
     * no link structurally targets it, or none of those has a resource
     * endpoint (without one there's no way to sync a single element, only the
     * full-list sweep).
     */
    public function html(ElementInterface $element): ?string
    {
        $candidates = $this->candidates($element);

        if ($candidates === []) {
            return null;
        }

        $redirect = $element->getCpEditUrl();

        return Craft::$app->getView()->renderTemplate('influx/_sync-button', [
            'candidates'     => $candidates,
            'hashedRedirect' => $redirect ? Craft::$app->getSecurity()->hashData($redirect) : null,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * One descriptor per link the element can be synced from, in link order.
     * A link without a resource endpoint is skipped entirely; one that simply
     * can't run right now is still offered, disabled — see {@see candidate()}.
     *
     * @return list<array{name: string, enabled: bool, reason: ?string, params: array<string, mixed>}>
     */
    public function candidates(ElementInterface $element): array
    {
        $candidates = [];

        foreach ($this->linksForElement($element) as $link) {
            if (! $link->itemEndpoint) {
                continue;
            }

            $candidates[] = $this->candidate($link, $element);
        }

        return $candidates;
    }

    /**
     * One candidate descriptor: the link's display name, whether it's currently
     * syncable, the reason it isn't (for the disabled state's info HUD), and the
     * `data-params` the formsubmit posts — carrying the explicit link handle so
     * the action syncs THIS link
     * ({@see \GlueAgency\Influx\controllers\SynchronizationController::actionElement()}).
     *
     * A link is offered even when the element can't currently be synced (no
     * match value, or an active cool-down): that renders as a DISABLED button /
     * menu item with the reason attached, so the action is discoverable rather
     * than silently absent.
     *
     * Only a link with per-site endpoints scopes to one site, so `site` is left
     * off for a single-base-endpoint link — that always syncs the primary site,
     * so pinning the action to the editor's current site would misrepresent it.
     *
     * @return array{name: string, enabled: bool, reason: ?string, params: array<string, mixed>}
     */
    public function candidate(Link $link, ElementInterface $element): array
    {
        $enabled = true;
        $reason = null;

        $matchAttr = $link->matchAttribute();

        // A match-less link needs no key on the element: the element IS what the
        // link writes, so the only thing that can hold the action back is the
        // cooldown. Demanding a match value here left a global-set link's button
        // permanently disabled with a reason no operator could act on.
        if ($link->requiresMatch() && (! $matchAttr || $element->{$matchAttr} === null || $element->{$matchAttr} === '')) {
            $enabled = false;
            $reason = Craft::t('influx', 'This element has no value for the match field, so it can’t be synced from remote.');
        } elseif ($this->cooldownRemaining($link, $element) > 0) {
            $enabled = false;
            $reason = Craft::t('influx', 'Recently synced');
        }

        $params = ['elementId' => $element->id, 'link' => $link->handle];

        if ($link->siteHandles() !== []) {
            $params['site'] = $element->getSite()->handle;
        }

        return [
            'name'    => $link->name,
            'enabled' => $enabled,
            'reason'  => $reason,
            'params'  => $params,
        ];
    }

    /**
     * Every link that structurally targets this element, via the plugin
     * singleton — a seam, so the candidate rules can be specced without a
     * booted plugin.
     *
     * @return Link[]
     */
    protected function linksForElement(ElementInterface $element): array
    {
        return Influx::getInstance()?->links?->findLinksForElement($element) ?? [];
    }

    /**
     * Seconds left on this element's manual-sync cool-down for the link, via the
     * plugin singleton — the second seam the candidate spec stubs.
     */
    protected function cooldownRemaining(Link $link, ElementInterface $element): int
    {
        return Influx::getInstance()?->cooldown?->remaining($link, $element) ?? 0;
    }
}
