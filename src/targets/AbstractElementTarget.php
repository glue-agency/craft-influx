<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\ElementHelper;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\helpers\Comparable;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use Throwable;

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
     */
    public function claimsElement(Link $link, ElementInterface $element): bool
    {
        if (! $this->targetsElement($link, $element)) {
            return false;
        }

        $matchAttr = $link->matchAttribute();

        if (! $matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
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
     * Dispatched by handle from {@see applyNativeAttribute()}, whose
     * `method_exists()` lookup sees inherited parsers like this one.
     */
    protected function parseEnabled(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $new = Lightswitch::coerce($mapping->resolve($item));

        $changed = (bool) $element->enabled !== $new;
        $element->enabled = $new;

        return $changed;
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
    public function afterCommit(SyncContext $context, ElementInterface $element, bool $isNew): void
    {
    }

    /**
     * Craft's own element save with validation OFF — deliberate: the feed is
     * authoritative, so a value Craft would reject (an over-long title, a
     * required field the feed left empty) still has to land rather than dropping
     * the whole item. What genuinely needs coercing is coerced on the way in
     * instead — {@see EntryTarget::parseTitle()} truncates, an unparseable date
     * is a no-op — and
     * {@see \GlueAgency\Influx\sync\item\ItemProcessor::commit()} reports a false
     * return as an ERROR row.
     *
     * (Feed Me takes the opposite side of that trade: it saves WITH validation,
     * `saveElement($element, true, true, $updateSearchIndexes)`. Worth knowing,
     * because anything Craft only does during validation has to be done here
     * instead — {@see ensureSlug()} is one such thing.)
     *
     * Every save the engine and the sweep perform routes through here, so a
     * target that needs different flags overrides this one method.
     */
    public function save(ElementInterface $element): bool
    {
        $this->ensureSlug($element);

        return Craft::$app->getElements()->saveElement($element, false);
    }

    /**
     * Derive an empty slug from the title, because skipping validation skips the
     * only thing that would have.
     *
     * Craft generates a slug in {@see \craft\validators\SlugValidator}, so with
     * validation off a created element keeps a null slug — and with no slug it
     * gets no URI either, leaving it unreachable on the front end. Mirrors the
     * validator rather than inventing a scheme: same `hasUris()` gate, same title
     * source, same `limitAutoSlugsToAscii` setting, same site language.
     *
     * Only fills a blank. A slug the feed mapped, or one an editor set, is left
     * exactly as it is.
     */
    protected function ensureSlug(ElementInterface $element): void
    {
        if (! $element::hasUris() || ! property_exists($element, 'slug')) {
            return;
        }

        if (($element->slug ?? '') !== '') {
            return;
        }

        $title = (string) ($element->title ?? '');

        if ($title === '') {
            return;
        }

        try {
            $language = $element->getSite()->language;
        } catch (Throwable) {
            $language = null;
        }

        $element->slug = ElementHelper::generateSlug(
            $title,
            Craft::$app->getConfig()->getGeneral()->limitAutoSlugsToAscii,
            $language,
        );
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
     * Mapping descriptors for the custom fields on a field layout, walked tab by
     * tab so they keep the element editor's own grouping. Targets differ only in
     * how they reach the layout (per entry type, the global user layout, ...)
     * and in what an unnamed tab falls back to — a missing layout yields nothing
     * so a target with unresolved criteria still reports its natives.
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
                    fieldMeta: Influx::getInstance()->fields->metaFor($field),
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
