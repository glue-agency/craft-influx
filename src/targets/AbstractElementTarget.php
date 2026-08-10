<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use DateTimeInterface;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

abstract class AbstractElementTarget implements ElementTargetInterface
{
    public function handles(Link $link): bool
    {
        return ltrim($link->elementType, '\\') === ltrim(static::elementType(), '\\');
    }

    /**
     * Default structural targeting: the link points at this target's element
     * type and the element is an instance of it. This is the whole rule for
     * element types with no sub-partition (see {@see \GlueAgency\Influx\targets\UserTarget},
     * which inherits it unchanged); targets that scope further — e.g.
     * {@see EntryTarget} on section/type — override this to add those checks.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        return $this->handles($link) && is_a($element, static::elementType());
    }

    /**
     * The claim rule for every target: structurally targeted PLUS a non-empty
     * value for the link's match attribute — the gap {@see targetsElement()}
     * deliberately leaves (an in-scope element with no match value still
     * targets, so the "Sync from remote" button can surface disabled). Both
     * halves are already per-target / per-link abstractions
     * ({@see targetsElement()}, {@see Link::matchAttribute()}), so no target has
     * ever needed its own version.
     *
     * A link that identifies its element from criteria ({@see Link::requiresMatch()})
     * claims on the structural test alone — there is no match value to be missing,
     * so requiring one would report that such a link claims nothing.
     */
    public function claimsElement(Link $link, ElementInterface $element): bool
    {
        if (! $this->targetsElement($link, $element)) {
            return false;
        }

        if (! $link->requiresMatch()) {
            return true;
        }

        $matchAttr = $link->matchAttribute();

        if (! $matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
    }

    /**
     * Default: available exactly when the declared element class loads and is a
     * Craft element. `is_subclass_of()` autoloads by name and answers false for a
     * class that isn't there instead of throwing, so this single line covers every
     * built-in (Craft's own element classes are always present) and every
     * third-party target whose plugin either ships its element class or doesn't.
     *
     * See {@see ElementTargetInterface::isAvailable()} for why targets are gated
     * and field strategies aren't.
     */
    public static function isAvailable(): bool
    {
        return is_subclass_of(static::elementType(), ElementInterface::class);
    }

    /**
     * Default: delegate to the element class's own `displayName()`. Subclasses
     * override only when they need a label distinct from Craft's own.
     */
    public static function friendlyName(): string
    {
        $class = static::elementType();

        if (is_subclass_of($class, ElementInterface::class)) {
            return $class::displayName();
        }
        $parts = explode('\\', ltrim($class, '\\'));

        return end($parts) ?: $class;
    }

    /**
     * Default: element types are localizable, so their links can run per-site.
     * Non-localizable targets (see {@see \GlueAgency\Influx\targets\UserTarget})
     * override this to false.
     */
    public static function supportsMultiSite(): bool
    {
        return true;
    }

    /**
     * Default: no scoping criteria. Targets whose element type is scoped by
     * extra query refinements (see {@see EntryTarget}) override this.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array
    {
        return [];
    }

    /**
     * Default: nothing to fill in, matching the empty {@see criteriaKeys()}. A
     * target that declares keys declares a node per key here.
     *
     * @return list<array>
     */
    public static function criteriaSchema(): array
    {
        return [];
    }

    /**
     * Default: nothing to show, since the base declares no criteria. Targets that
     * do override this to name what the link is scoped to.
     */
    public function criteriaLabel(Link $link): ?string
    {
        return null;
    }

    /**
     * The leading "nothing picked" row every criteria dropdown opens with — one
     * spelling of the sentinel, shared by every target that declares criteria.
     * Its empty value is what {@see Link::criterion()} reads back as null.
     *
     * @return array{value: string, label: string}
     */
    protected static function criteriaPlaceholder(): array
    {
        return ['value' => '', 'label' => Craft::t('influx', '— select —')];
    }

    /**
     * Default: creatable. Almost every element type can be brought into being by
     * a feed; the exception is one whose elements are declared in project config
     * ({@see \GlueAgency\Influx\targets\GlobalSetTarget}), which overrides to false.
     */
    public static function supportsCreating(): bool
    {
        return true;
    }

    /**
     * Default: sweepable. Most element types can enumerate what a link owns, so
     * the builder offers the missing-element policies; a type that can't (see
     * {@see \GlueAgency\Influx\targets\UserTarget}) overrides this to false and
     * leaves {@see missingElementsQuery()} at its null default.
     */
    public static function supportsSweeping(): bool
    {
        return true;
    }

    /**
     * Default: a link identifies its elements by a match value. True for every
     * element type whose criteria name a SET of elements — which is all of them
     * except a Global Set, and an Entry link scoped to a Craft Single
     * ({@see \GlueAgency\Influx\targets\GlobalSetTarget},
     * {@see \GlueAgency\Influx\targets\EntryTarget}).
     *
     * Link-scoped rather than static because the entry case can only be answered
     * once the link's section is known. See
     * {@see ElementTargetInterface::requiresMatch()} for the contract, including
     * why an unresolvable criterion must still answer true.
     */
    public function requiresMatch(Link $link): bool
    {
        return true;
    }

    /**
     * No criteria-only resolution by default: a target reaching here declared it
     * needs no match value but never said what element to write instead, which is
     * a half-implemented target rather than a runtime condition.
     *
     * Throwing for the same reason {@see buildNew()} does on a non-creating
     * target: silence would resolve every item to null and report a run of skips
     * that looks like a feed problem.
     *
     * @throws InfluxException always.
     */
    public function findWithoutMatch(Link $link, ?int $siteId = null): ?ElementInterface
    {
        throw new InfluxException(sprintf(
            "%s reports that link '%s' needs no match value but doesn't implement findWithoutMatch().",
            static::class,
            $link->handle,
        ));
    }

    /**
     * Default: one sentinel cell — the element type has no sub-partition, so
     * every link to it claims the same, undivided set and any two of them
     * overlap. Targets whose element type IS partitioned (see
     * {@see EntryTarget}) expand the link's criteria into cells instead.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        return [self::CLAIM_ALL];
    }

    /**
     * Default native-attribute apply: resolve the remote value (`node` then
     * `default`) and assign it via setAttribute, falling back to setFieldValue
     * for attrs Craft exposes that way. A `parseFoo` method wins when one
     * exists, translating values that aren't directly assignable — declared here
     * for the universal `enabled` flag ({@see parseEnabled()}) and on the target
     * for its own natives; see the convention documented on
     * {@see ElementTargetInterface::applyNativeAttribute()}. The run's
     * {@see SyncContext} is passed to those parsers (first argument) so they
     * can reach the run's element-lookup cache.
     *
     * The caller ({@see \GlueAgency\Influx\sync\item\MappingApplier}) only invokes
     * this for an actively-mapped handle, so an empty resolved value here means
     * "actively mapped, now empty" — written through to clear the attribute
     * (the feed is authoritative). Returns whether the value actually changed
     * (generic before/after comparison for the default path; `parseFoo`
     * overrides own their own attribute-aware comparison).
     */
    public function applyNativeAttribute(
        SyncContext $context,
        ElementInterface $element,
        string $handle,
        RemoteItem $item,
        FieldMapping $mapping,
    ): bool {
        $method = 'parse' . ucfirst($handle);

        if (method_exists($this, $method)) {
            return (bool) $this->{$method}($context, $element, $item, $mapping);
        }

        $value = $mapping->resolve($item);
        $isAttribute = in_array($handle, $element->attributes(), true) || property_exists($element, $handle);

        if ($isAttribute) {
            $before = $element->{$handle} ?? null;
            $element->{$handle} = $value;

            return $this->nativeValueChanged($before, $element->{$handle} ?? null);
        }

        $before = $element->getFieldValue($handle);
        $element->setFieldValue($handle, $value);

        return $this->nativeValueChanged($before, $element->getFieldValue($handle));
    }

    /**
     * Coerce the mapped value into the `enabled` flag — shared by every target,
     * since `enabled` is the one status flag every element type carries. (Craft
     * derives an entry's `status` from enabled + postDate + expiryDate and a
     * user's from enabled + its account state, so neither can be set directly:
     * that's why the native mappable is `enabled`.) Truthy spellings come from
     * {@see Lightswitch::coerce()}, so an addressed-but-empty value coerces to
     * false, i.e. disabled.
     *
     * Written per site, with the whole-element flag DERIVED from the result:
     * the mapped value lands on the site being processed, every other site keeps
     * the status it had, and `enabled` becomes "enabled in at least one site".
     * A link fanning out over sites shares one canonical element, so writing the
     * whole-element flag directly would let one site's feed — most obviously a
     * per-language `/deleted` endpoint — retire that element everywhere. Feed Me
     * resolves it the same way ({@see \craft\feedme\services\Process}), which is
     * why its per-language disable feeds only ever removed an entry from their
     * own site.
     *
     * WHICH of the two happens is decided by the link's endpoint shape, read off
     * the run rather than the element: `$context->siteId` is set only for a
     * per-site endpoint, and is null when the link has one endpoint for
     * everything. A single-endpoint link is making a statement about the element
     * as a whole, so it writes the whole-element flag — the site rows follow
     * through the section's own propagation. Reading the site off the ELEMENT
     * instead would be wrong here: a cross-site lookup resolves through
     * `siteId('*')->unique()`, so `$element->siteId` is whichever row Craft
     * happened to return, and the status would land on an arbitrary site.
     *
     * Element types with no per-site status use the whole-element flag too.
     *
     * Dispatched by handle from {@see applyNativeAttribute()}, whose
     * `method_exists()` lookup sees inherited parsers like this one.
     */
    protected function parseEnabled(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $new = Lightswitch::coerce($mapping->resolve($item));
        $siteId = $context->siteId;

        if ($siteId === null || ! $element::isLocalized()) {
            $changed = (bool) $element->enabled !== $new;
            $element->enabled = $new;

            return $changed;
        }

        $statuses = [];

        foreach (Craft::$app->getSites()->getAllSiteIds(true) as $id) {
            $status = $element->getEnabledForSite($id);

            if ($status !== null) {
                $statuses[$id] = $status;
            }
        }

        $wasForSite = (bool) $element->getEnabledForSite($siteId);
        $wasEnabled = (bool) $element->enabled;

        $statuses[$siteId] = $new;

        $element->setEnabledForSite($statuses);
        $element->enabled = in_array(true, $statuses, true) || $wasEnabled;

        return $wasForSite !== $new || $wasEnabled !== (bool) $element->enabled;
    }

    /**
     * An empty value clears the date — the feed is authoritative. Parsing is
     * {@see Date::tryParse()}, the same rule the custom Date field uses; the
     * policy for its null differs on purpose: an unparseable value is a no-op
     * here, because malformed feed data must not wipe a stored native date (the
     * field strategy throws instead, surfacing an error row).
     *
     * Shared rather than per-target: every element type that carries a date
     * attribute writes it the same way, and the two that do so far
     * ({@see EntryTarget}'s postDate/expiryDate,
     * {@see \GlueAgency\Influx\integrations\solspace\calendar\EventTarget}'s
     * postDate) had no reason to differ.
     */
    protected function assignDate(ElementInterface $element, string $attr, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);
        $before = $element->{$attr};

        if ($value === null || $value === '') {
            $element->{$attr} = null;

            return $before !== null;
        }

        $parsed = Date::tryParse($value, $mapping->option('format'));

        if ($parsed === null) {
            return false;
        }

        $element->{$attr} = $parsed;

        return ! ($before instanceof DateTimeInterface) || $before->getTimestamp() !== $parsed->getTimestamp();
    }

    /**
     * Whether two native values differ, compared on a stable, type-aware
     * representation — so re-applying the same author/date/flag isn't mistaken
     * for a change.
     */
    protected function nativeValueChanged(mixed $before, mixed $after): bool
    {
        return $this->comparable($before) !== $this->comparable($after);
    }

    /**
     * The target layer's seam onto {@see Comparable::of()} — the one comparison
     * normaliser, shared with the custom-field strategies
     * ({@see \GlueAgency\Influx\fields\Field::normalize()}).
     */
    protected function comparable(mixed $value): mixed
    {
        return Comparable::of($value);
    }

    /**
     * Default: nothing is owned — every mapped handle goes through the generic
     * native/custom dispatch. Targets override only for a handle the engine must
     * not write itself: one the target already assigned, or one no element save
     * can persist (see {@see \GlueAgency\Influx\targets\UserTarget::ownsAttribute()},
     * whose `groups` is reconciled in afterCommit() instead).
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return false;
    }

    /**
     * Default: assign the match value as a native attribute when one exists,
     * otherwise treat it as a custom field. Works for every element type so
     * far; targets only need to override for non-standard match storage.
     */
    public function assignMatchValue(ElementInterface $element, Link $link, mixed $matchValue): void
    {
        $attr = $link->matchAttribute();

        if (! $attr) {
            return;
        }

        if (in_array($attr, $element->attributes(), true) || property_exists($element, $attr)) {
            $element->{$attr} = $matchValue;
        } else {
            $element->setFieldValue($attr, $matchValue);
        }
    }

    /**
     * Default: no post-commit side effects. Targets override when they manage
     * state outside the element save (see {@see \GlueAgency\Influx\targets\UserTarget}).
     */
    public function afterCommit(SyncContext $context, ElementInterface $element, RemoteItem $item, bool $isNew): void
    {
    }

    /**
     * Craft's own element save, WITH validation — the same call Feed Me makes
     * (`saveElement($element, true, true, $updateSearchIndexes)`).
     *
     * Validation is where Craft fills in work nothing else does: an empty slug is
     * derived from the title in {@see \craft\validators\SlugValidator}, and
     * skipping it left created elements with no slug and therefore no URI, so
     * they had no front-end URL at all. Rather than reproduce each of those by
     * hand as they surface, run the validation Craft expects.
     *
     * The trade is that a value Craft rejects now fails the save instead of being
     * forced through, and {@see \GlueAgency\Influx\sync\item\ItemProcessor::commit()}
     * reports that as an ERROR row. That is the louder failure of the two: the
     * item is reported rather than landing in a state Craft considers invalid.
     * Coercion on the way in still does the obvious work first —
     * {@see EntryTarget::parseTitle()} truncates an over-long title, an
     * unparseable date is a no-op — so the common feed messiness never reaches
     * the validator.
     *
     * Every save the engine and the sweep perform routes through here, so a
     * target that needs different flags overrides this one method.
     */
    public function save(ElementInterface $element): bool
    {
        return Craft::$app->getElements()->saveElement($element);
    }

    public function disable(ElementInterface $element): bool
    {
        $element->enabled = false;

        return $this->save($element);
    }

    /**
     * Disable the element in one site only. `setEnabledForSite([$siteId =>
     * false])` (the siteId-keyed array form) is stable across Craft 4 and 5 —
     * no Compat seam needed. The whole-element `enabled` flag is left alone so
     * the element stays live in its other sites; only the passed site's
     * per-site row flips off.
     */
    public function disableForSite(ElementInterface $element, int $siteId): bool
    {
        $element->setEnabledForSite([$siteId => false]);

        return $this->save($element);
    }

    public function delete(ElementInterface $element): bool
    {
        return Craft::$app->getElements()->deleteElement($element);
    }

    public function deleteForSite(ElementInterface $element, int $siteId): bool
    {
        Compat::deleteElementForSite($element, $siteId);

        return true;
    }

    /**
     * Default: no missing-elements query. A target that can't safely enumerate
     * "everything this link owns" reports {@see supportsSweeping()} = false —
     * which is what keeps the policies off the builder and bails the sweep with a
     * reported skip — and leaves this at null as the last line of defence.
     * Targets that can sweep (see {@see EntryTarget}) override with a scoped,
     * seen-excluding query.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        return null;
    }

    public function getMappableFields(Link $link): array
    {
        return [];
    }

    /**
     * Default: no layout, matching the empty {@see getMappableFields()}.
     */
    public function fieldLayout(Link $link): ?FieldLayout
    {
        return null;
    }

    /**
     * Mapping descriptors for the custom fields on a field layout, walked tab by
     * tab so they keep the element editor's own grouping. Targets differ only in
     * how they reach the layout ({@see fieldLayout()}) and in what an unnamed tab
     * falls back to — a missing layout yields nothing so a target with unresolved
     * criteria still reports its natives.
     *
     * @return list<MappableField>
     */
    protected function customFieldDescriptors(?FieldLayout $layout, string $fallbackTab): array
    {
        if (! $layout) {
            return [];
        }

        $fields = [];

        foreach ($layout->getTabs() as $tab) {
            $tabName = $tab->name ?: $fallbackTab;

            foreach ($tab->getElements() as $layoutElement) {
                if (! ($layoutElement instanceof CustomField)) {
                    continue;
                }
                $field = $layoutElement->getField();

                if (! $field) {
                    continue;
                }
                $fields[] = MappableField::custom(
                    handle: $field->handle,
                    name: $field->name,
                    group: $tabName,
                    fieldClass: $field::class,
                    // The row's whole UI, declared once by the field's own strategy.
                    mapping: Influx::getInstance()->fields->rowFor($field)->toArray(),
                );
            }
        }

        return $fields;
    }

    /**
     * Default matchable natives: only `id` — the one identifier every
     * Craft element is guaranteed to have. Targets extend with whatever
     * their element type actually exposes (see {@see EntryTarget}).
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return [
            ['value' => 'id', 'label' => Craft::t('influx', 'ID (id)')],
        ];
    }
}
