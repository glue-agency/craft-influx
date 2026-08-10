<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\GlobalSet;
use craft\models\FieldLayout;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * Target for craft\elements\GlobalSet.
 *
 * Recognized elementCriteria key — {@see CRITERIA_SET}, the handle of the global
 * set (which carries the field layout the mappings write to). This target OWNS
 * that key name.
 *
 * UPDATE-ONLY, which is the whole shape of it. A global set exists because
 * someone declared it in project config; a feed can fill it in but can never
 * bring one into being, so this target reports {@see supportsCreating()} = false
 * (the builder drops the `create` policy, {@see Link::pruneProcessingForTarget()}
 * drops it from a saved link, and {@see buildNew()} throwing is the last line of
 * defence). Nor can it be swept: "every global set this link owns" is exactly one
 * element, always present, so {@see supportsSweeping()} = false and the
 * disable-/delete-missing policies are never offered — and dropped from a saved
 * link by the same prune.
 *
 * NO MATCH KEY, and no native mappable rows at all. {@see CRITERIA_SET} already
 * names exactly one element, so there is nothing for a match value to
 * disambiguate: {@see requiresMatch()} = false and {@see findWithoutMatch()}
 * resolves the set straight from the criterion. A global set has no title and no
 * slug either, so the only identifier a feed could have carried was the set's own
 * `handle` — which is project config, not content, and asking an operator to
 * nominate a feed node for it was asking them to re-state something the criterion
 * had already pinned.
 *
 * {@see ownsAttribute()} still claims `handle` even though no row offers it. That
 * claim is now a GUARD rather than a UI concern: a link saved before the row was
 * removed keeps `mappings.handle` in stored config (nothing prunes it — see
 * {@see \GlueAgency\Influx\services\LinksService::pruneMappings()}, which never
 * runs on `project-config/apply` and bails on a layout with no custom fields), and
 * without the claim that stale row would route through the generic native path and
 * assign `$element->handle` from the feed. Rewriting project config out of a JSON
 * payload is the one failure worth keeping four dead-looking lines for.
 *
 * Global set content IS per-site, so unlike User this target supports multi-site:
 * a link can carry one endpoint per site and write localized values to the same
 * canonical set.
 */
class GlobalSetTarget extends AbstractElementTarget
{
    public const CRITERIA_SET = 'set';

    /**
     * The set's own handle. No longer offered as a mappable row — it's kept as a
     * constant because {@see ownsAttribute()} still names it to keep a stale stored
     * mapping from reaching the element (see the class docblock).
     */
    protected const HANDLE_HANDLE = 'handle';

    public static function elementType(): string
    {
        return GlobalSet::class;
    }

    /** @return list<string> */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_SET];
    }

    /** @return list<array> */
    public static function criteriaSchema(): array
    {
        $options = [self::criteriaPlaceholder()];

        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            $options[] = ['value' => $set->handle, 'label' => $set->name];
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_SET,
                'label'   => Craft::t('app', 'Global Set'),
                'options' => $options,
            ])
            ->toArray();
    }

    public function criteriaLabel(Link $link): ?string
    {
        $handle = $link->criterion(self::CRITERIA_SET);

        if (! $handle) {
            return null;
        }

        return $this->setByHandle($handle)?->name ?? $handle;
    }

    /**
     * A global set is declared in project config, never conjured by a feed — see
     * the class docblock.
     */
    public static function supportsCreating(): bool
    {
        return false;
    }

    /**
     * There is nothing to sweep: a link's set is one always-present element, so
     * the complement of "what the feed mentioned" is either empty or the set
     * itself, and disabling or deleting a global set on a feed's silence is never
     * what an operator meant.
     */
    public static function supportsSweeping(): bool
    {
        return false;
    }

    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof GlobalSet)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $setHandle = $link->criterion(self::CRITERIA_SET);

        return $setHandle === null || $element->handle === $setHandle;
    }

    /**
     * Global sets partition by set — the set the link names, or every set there is
     * when it names none.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        $set = $link->criterion(self::CRITERIA_SET);

        if ($set !== null) {
            return [$set];
        }

        $cells = [];

        foreach (Craft::$app->getGlobals()->getAllSets() as $candidate) {
            $cells[] = $candidate->handle;
        }

        return $cells;
    }

    /**
     * A global-set link never matches, so this is unreachable through the engine
     * ({@see \GlueAgency\Influx\sync\item\ItemProcessor::resolve()} routes a
     * no-match target to {@see findWithoutMatch()}). Kept honest rather than
     * removed — the interface still declares it — by resolving the same element
     * regardless of the value, so an out-of-band caller gets the link's set rather
     * than a lie about having looked something up.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?GlobalSet
    {
        return $this->findWithoutMatch($link, $siteId);
    }

    /**
     * The set {@see CRITERIA_SET} names. Null when the link names none — the
     * criterion is what identifies the element now, so an unset one leaves nothing
     * to write, which the engine reports the way it reports a match that found
     * nothing.
     *
     * A siteless run resolves the canonical row; a per-site run resolves the row
     * for that site, so localized content lands on the right one.
     */
    public function findWithoutMatch(Link $link, ?int $siteId = null): ?GlobalSet
    {
        $handle = $link->criterion(self::CRITERIA_SET);

        return $handle !== null ? $this->queryOne($handle, $siteId) : null;
    }

    /**
     * The lookup itself, isolated as a seam so the resolution above is testable
     * without a booted Craft.
     */
    protected function queryOne(string $handle, ?int $siteId): ?GlobalSet
    {
        $query = GlobalSet::find()
            ->status(null)
            ->handle($handle);

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query->one();
    }

    /**
     * The criterion names one element, so there is nothing to match on.
     */
    public function requiresMatch(Link $link): bool
    {
        return false;
    }

    /**
     * Never called through the engine — a global-set link can't carry the `create`
     * policy — so reaching here means config was hand-edited past both the builder
     * and the save-time prune. Fail loudly rather than conjure a set the project
     * config doesn't declare.
     *
     * @throws InfluxException always.
     */
    public function buildNew(Link $link, ?int $siteId = null): GlobalSet
    {
        throw new InfluxException(
            "Link '{$link->handle}' can't create global sets — they're declared in project config. Turn off 'create' for this link.",
        );
    }

    /**
     * A set's handle is project config that no sync may rewrite. No row offers it
     * any more, so this exists purely to neutralise a mapping saved before the row
     * was removed — see the class docblock for why that mapping outlives the
     * change and what the claim prevents.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return $handle === self::HANDLE_HANDLE;
    }

    /**
     * Nothing: a link that needs no match value has no match options to offer.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return [];
    }

    /**
     * Only the configured set's own field layout — a global set has no native
     * attribute a feed may write, so there is no native group at all. An
     * unresolvable set reports nothing, which is the honest answer: without a set
     * there is no layout and nothing to map.
     */
    public function getMappableFields(Link $link): array
    {
        return $this->customFieldDescriptors(
            $this->fieldLayout($link),
            Craft::t('influx', 'Content'),
        );
    }

    public function fieldLayout(Link $link): ?FieldLayout
    {
        return $this->set($link)?->getFieldLayout();
    }

    /**
     * Lenient set resolution for UI/read paths — an unset or unknown handle yields
     * null, so a half-configured link still reports its `handle` row.
     */
    protected function set(Link $link): ?GlobalSet
    {
        $handle = $link->criterion(self::CRITERIA_SET);

        return $handle ? $this->setByHandle($handle) : null;
    }

    /**
     * The one Craft lookup, isolated as a seam so the resolution above is testable
     * without a booted Craft.
     */
    protected function setByHandle(string $handle): ?GlobalSet
    {
        return Craft::$app->getGlobals()->getSetByHandle($handle);
    }
}
