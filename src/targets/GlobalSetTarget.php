<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\GlobalSet;
use craft\models\FieldLayout;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
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
 * disable-/delete-missing policies are never offered.
 *
 * Matching still works the ordinary way, off the link's match key — no special
 * case in the engine. A global set has no title and no slug, so the identifier a
 * feed can carry is its `handle` ({@see NativeAttributes::globalSetMatchable()}),
 * which is why {@see nativeFieldDefinitions()} declares a `handle` row: the match
 * value has to come from a mapping with a source node ({@see Link::validateMatch()}),
 * and that row is where the operator says which node it is. Nothing writes it —
 * {@see ownsAttribute()} claims it, the way {@see UserTarget} claims `groups` — a
 * handle is project config, not content.
 *
 * Global set content IS per-site, so unlike User this target supports multi-site:
 * a link can carry one endpoint per site and write localized values to the same
 * canonical set.
 */
class GlobalSetTarget extends AbstractElementTarget
{
    public const CRITERIA_SET = 'set';

    /**
     * The mapping handle the match value is read from. Declared as a constant
     * because two members have to agree on it: the descriptor
     * {@see nativeFieldDefinitions()} declares and the {@see ownsAttribute()} claim
     * that keeps the applier from writing it.
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
     * The set the item's match value names, within the set the link is scoped to.
     * A siteless run resolves the canonical row; a per-site run resolves the row
     * for that site, so the localized content lands on the right one.
     *
     * The criterion is checked on the RESULT rather than added to the query. The
     * usual match attribute here is `handle`, which is the same query refinement
     * the criterion would use — so scoping the query would have let the criterion
     * silently overwrite the feed's own value, resolving every item to the
     * configured set no matter what it said. Checked afterwards, the criterion
     * stays what it is everywhere else: a boundary the match has to fall inside.
     */
    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?GlobalSet
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $set = $this->queryOne($matchAttr, $matchValue, $siteId);

        if ($set === null) {
            return null;
        }

        $criterion = $link->criterion(self::CRITERIA_SET);

        return $criterion === null || $set->handle === $criterion ? $set : null;
    }

    /**
     * The lookup itself, isolated as a seam so the criterion check above is
     * testable without a booted Craft.
     */
    protected function queryOne(string $matchAttr, mixed $matchValue, ?int $siteId): ?GlobalSet
    {
        $query = GlobalSet::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query->one();
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
     * The `handle` row is config-only: it exists so the match value has a mapping
     * with a source node, and a global set's handle is project config that no sync
     * may rewrite. Claiming it here keeps the mapping applier from trying.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return $handle === self::HANDLE_HANDLE;
    }

    public function matchableNativeAttributes(Link $link): array
    {
        return NativeAttributes::globalSetMatchable();
    }

    /**
     * Custom fields come from the configured set's own field layout, so they keep
     * their globals-editor grouping; an unresolvable set leaves the `handle` row
     * alone, which is enough to configure the match key with.
     */
    public function getMappableFields(Link $link): array
    {
        return array_merge(
            $this->nativeFieldDefinitions()->toArray(),
            $this->customFieldDescriptors(
                $this->fieldLayout($link),
                Craft::t('influx', 'Content'),
            ),
        );
    }

    public function fieldLayout(Link $link): ?FieldLayout
    {
        return $this->set($link)?->getFieldLayout();
    }

    /**
     * The one native row: the set's handle, purely as a place to declare which feed
     * node identifies the set. No default cell — a default would make every item
     * resolve to the same set regardless of what the feed said, which is what
     * {@see Link::matchValue()} already refuses to allow for a match value.
     */
    protected function nativeFieldDefinitions(): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), fn(MappingSchemaBuilder $group) => $group
                ->text([
                    'handle' => self::HANDLE_HANDLE,
                    'name'   => Craft::t('app', 'Handle'),
                    'cells'  => ['default' => false],
                ]));
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
