<?php

namespace GlueAgency\Influx\helpers;

use Craft;
use craft\base\Chippable;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Site;
use craft\services\Sections;
use DateTime;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\web\LinkChip;
use yii\web\Response;

/**
 * Single seam between Influx and the Craft 4 / Craft 5 API differences.
 *
 * Every check is feature detection (class/method/property existence) rather
 * than version parsing: each branch names the exact API it papers over, and
 * point releases that backport a method automatically take the native path.
 *
 * Supported range: Craft 4.0 – 5.x.
 *
 * Not the only compat seam in the plugin: the console-output version fallbacks
 * live in a trait of their own.
 *
 * @see \GlueAgency\Influx\console\ConsoleOutputCompatTrait
 */
class Compat
{
    /**
     * The Sections service was removed in Craft 5 (section + entry-type
     * lookups moved to the Entries service) — its absence is the marker.
     */
    public static function isCraft5(): bool
    {
        return ! class_exists(Sections::class);
    }

    /**
     * @return Section[]
     */
    public static function getAllSections(): array
    {
        return static::sectionsService()->getAllSections();
    }

    public static function getSectionByHandle(string $handle): ?Section
    {
        return static::sectionsService()->getSectionByHandle($handle);
    }

    public static function getSectionById(int $id): ?Section
    {
        return static::sectionsService()->getSectionById($id);
    }

    public static function getSectionByUid(string $uid): ?Section
    {
        return static::sectionsService()->getSectionByUid($uid);
    }

    public static function getEntryTypeById(int $id): ?EntryType
    {
        return static::sectionsService()->getEntryTypeById($id);
    }

    /**
     * The service every section / entry-type lookup above goes through: Craft 4
     * keeps them on `Craft::$app->getSections()`, Craft 5 moved them to
     * `Craft::$app->getEntries()`.
     *
     * @return \craft\services\Entries|Sections
     */
    protected static function sectionsService(): object
    {
        return static::isCraft5()
            ? Craft::$app->getEntries()
            : Craft::$app->getSections();
    }

    /**
     * Normalized block-type descriptors for a Matrix field. Craft 5 renamed
     * Matrix block types to nested entry types, so discovery moved from
     * getBlockTypes() to getEntryTypes(); this feature-detects the Craft 5 API
     * and falls back to the Craft 4 one. The returned layout is what a caller
     * reads to resolve a block's child fields by handle.
     *
     * `hasTitleField` says whether the block type exposes a native Title at
     * all — Craft 5 nested entry types carry the flag (EntryType::$hasTitleField),
     * and Craft 4 MatrixBlockType has no such property because a Craft 4 Matrix
     * block has no title to begin with. So an ABSENT property reads FALSE here,
     * the inverse of {@see entryTypeShowsTitleField()}, where absence means an
     * older entry type that always had a title. Same feature detection, opposite
     * default, because the two describe different elements.
     *
     * @return list<array{handle: string, name: string, layout: ?FieldLayout, hasTitleField: bool}>
     */
    public static function matrixBlockTypes(CraftFieldInterface $field): array
    {
        $blockTypes = method_exists($field, 'getEntryTypes')
            ? $field->getEntryTypes()
            : $field->getBlockTypes();

        $normalized = [];

        foreach ($blockTypes as $blockType) {
            $normalized[] = [
                'handle'        => $blockType->handle,
                'name'          => $blockType->name,
                'layout'        => $blockType->getFieldLayout(),
                'hasTitleField' => property_exists($blockType, 'hasTitleField') && $blockType->hasTitleField,
            ];
        }

        return $normalized;
    }

    /**
     * A throwaway, never-saved block element of the given block type — used
     * only as a layout/context carrier so a child field strategy can resolve
     * its Craft field and coerce a value. Returns null when the handle doesn't
     * resolve to a block type on the field.
     *
     * Craft 5: a new craft\elements\Entry bound to the matching EntryType.
     * Craft 4: a craft\elements\MatrixBlock — instantiated through a string
     * class name so this file never hard-references a symbol absent from the
     * Craft 5 vendor tree. {@see matrixBlockTypes()} covers the discovery half
     * of the same divergence.
     *
     * `fieldId` is as load-bearing as the type on both: without it a Craft 5
     * nested entry can't resolve an owner, so every property read throws
     * "Either `sectionId` or `fieldId` + `ownerId` must be set on the entry" —
     * including the `getFieldLayout()` call this block exists to serve, which
     * silently costs the caller every child value.
     */
    public static function newMatrixBlock(CraftFieldInterface $field, string $typeHandle): ?ElementInterface
    {
        if (method_exists($field, 'getEntryTypes')) {
            foreach ($field->getEntryTypes() as $entryType) {
                if ($entryType->handle === $typeHandle) {
                    return new Entry([
                        'typeId'  => $entryType->id,
                        'fieldId' => $field->id,
                    ]);
                }
            }

            return null;
        }

        foreach ($field->getBlockTypes() as $blockType) {
            if ($blockType->handle === $typeHandle) {
                $class = 'craft\\elements\\MatrixBlock';

                return new $class([
                    'typeId'  => $blockType->id,
                    'fieldId' => $field->id,
                ]);
            }
        }

        return null;
    }

    /**
     * The `DateTime::createFromFormat()` format a STORED date comes back in from
     * `Field::serializeValue()` — the shape change detection has to undo before it
     * can compare a stored date against an incoming one.
     *
     * The shape moved WITHIN Craft 4, not between the majors: 4.15 added
     * `serializeValueForDb()` (@since 4.15.0) and moved DB formatting there, after
     * which `serializeValue()` renders a DateTime as ISO-8601 carrying its own
     * offset (`DateTimeHelper::toIso8601()`, i.e. `DateTime::ATOM`) — Craft 5 does
     * the same. Before 4.15 `craft\fields\Date` overrode `serializeValue()` and
     * emitted `Db::prepareDateForDb()`, a UTC `Y-m-d H:i:s` string with no offset.
     * That method's presence on the field API is the marker, so a 4.x point
     * release that backports it reads right too.
     */
    public static function serializedDateFormat(): string
    {
        return method_exists(CraftFieldInterface::class, 'serializeValueForDb')
            ? DateTime::ATOM
            : 'Y-m-d H:i:s';
    }

    /**
     * EntryType::$showSlugField is @since 5.0 — Craft 4 entry types always
     * expose the slug attribute.
     */
    public static function entryTypeShowsSlugField(EntryType $entryType): bool
    {
        return ! property_exists($entryType, 'showSlugField') || $entryType->showSlugField;
    }

    /**
     * EntryType::$hasTitleField exists in both majors; feature-detected anyway
     * so a type model without it (a stub, a future rename) reads as "shown"
     * rather than throwing.
     */
    public static function entryTypeShowsTitleField(EntryType $entryType): bool
    {
        return ! property_exists($entryType, 'hasTitleField') || $entryType->hasTitleField;
    }

    /**
     * EntryType::$showStatusField is @since 4.5 — earlier Craft 4 entry types
     * always expose the status (enabled) control.
     */
    public static function entryTypeShowsStatusField(EntryType $entryType): bool
    {
        return ! property_exists($entryType, 'showStatusField') || $entryType->showStatusField;
    }

    /**
     * Craft 5 entries are multi-author (setAuthorIds() @since 5.0); Craft 4
     * entries take a single author ID. A null id clears the author.
     */
    public static function setEntryAuthor(Entry $entry, ?int $userId): void
    {
        if (method_exists($entry, 'setAuthorIds')) {
            $entry->setAuthorIds($userId === null ? [] : [$userId]);
        } else {
            $entry->setAuthorId($userId);
        }
    }

    /**
     * Current author id(s) of an entry, for change detection. Craft 5 entries
     * are multi-author (getAuthorIds() @since 5.0); Craft 4 entries carry a
     * single authorId. Reads from the in-memory value, so it reflects a just-
     * set author without a save.
     *
     * @return int[]
     */
    public static function entryAuthorIds(Entry $entry): array
    {
        if (method_exists($entry, 'getAuthorIds')) {
            return array_map('intval', $entry->getAuthorIds() ?? []);
        }

        return $entry->authorId ? [(int) $entry->authorId] : [];
    }

    /**
     * Deletes an element's presence in a single site.
     *
     * Elements::deleteElementForSite() is @since 4.4 and always acts on the
     * site the element instance was loaded in (it takes no site argument), so
     * the element is reloaded in the target site first. The 4.0–4.3 fallback
     * replicates the core method's essentials: a full delete when the target
     * site is the element's only site, otherwise dropping the site row and
     * invalidating caches. An element that isn't present in the target site at
     * all is a no-op.
     */
    public static function deleteElementForSite(ElementInterface $element, int $siteId): void
    {
        $elements = Craft::$app->getElements();

        if ((int) $element->siteId !== $siteId) {
            $element = $elements->getElementById($element->id, get_class($element), $siteId);

            if (! $element) {
                return;
            }
        }

        if (method_exists($elements, 'deleteElementForSite')) {
            $elements->deleteElementForSite($element);

            return;
        }

        $existsElsewhere = $element::find()
            ->id($element->id)
            ->status(null)
            ->drafts(null)
            ->siteId(['not', $siteId])
            ->exists();

        if (! $existsElsewhere) {
            $elements->deleteElement($element, true);

            return;
        }

        Db::delete(CraftTable::ELEMENTS_SITES, [
            'elementId' => $element->id,
            'siteId'    => $siteId,
        ]);
        $elements->invalidateCachesForElement($element);
    }

    /**
     * Whether $user (default: the current user) may save $element, via Craft's
     * element authorization API (Elements::canSave(), @since 4.3). Absent on
     * Craft 4.0–4.2, where this returns true and the caller's own permission
     * gate is the only check.
     */
    public static function canSaveElement(ElementInterface $element, ?User $user = null): bool
    {
        $elements = Craft::$app->getElements();

        if (method_exists($elements, 'canSave')) {
            return $elements->canSave($element, $user);
        }

        return true;
    }

    /**
     * Element chip HTML. Craft 5: Cp::elementChipHtml(); Craft 4:
     * Cp::elementHtml(), which has no `hyperlink` option — emulated with a
     * plain anchor wrap. Exposed to Twig as `influxElementChip()`.
     *
     * The emulation skips the anchor for a trashed element, matching Craft 5's
     * chip, which refuses to hyperlink one (its edit URL leads nowhere until it
     * is restored).
     */
    public static function elementChipHtml(ElementInterface $element, array $config = []): string
    {
        if (method_exists(Cp::class, 'elementChipHtml')) {
            return Cp::elementChipHtml($element, $config);
        }

        $html = Cp::elementHtml($element);

        if (! empty($config['hyperlink']) && ! $element->trashed) {
            $url = $element->getCpEditUrl();

            if ($url) {
                $html = Html::a($html, $url);
            }
        }

        return $html;
    }

    /**
     * Chip HTML for a link, labelled with its name and pointing at the link
     * builder.
     *
     * A link is a config model rather than an element, which Craft 5 chips just
     * as happily — `Cp::chipHtml()` takes any `Chippable`, and {@see LinkChip}
     * adapts the model into one. Craft 4 has no generic chip renderer (the
     * `Chippable` interface is the marker for one), so it falls back to the plain
     * hyperlinked name the overviews used before chips.
     *
     * `autoReload` is off: Craft's chip JS re-renders a chip by asking the
     * server for the component behind it, which only knows Craft's own types.
     */
    public static function linkChipHtml(Link $link, array $config = []): string
    {
        if (interface_exists(Chippable::class)) {
            return Cp::chipHtml(new LinkChip($link), $config + [
                'autoReload' => false,
                'hyperlink'  => true,
            ]);
        }

        $label = Html::encode($link->name);

        return $link->id
            ? Html::a($label, UrlHelper::cpUrl('influx/links/' . $link->id))
            : $label;
    }

    /**
     * Chip HTML for one site, by handle — the single renderer behind every site
     * the CP displays. Exposed to Twig as `influxSiteChip()`.
     *
     * A null (or empty) handle is the plugin's "no site configured / no site
     * scope" state, which means the primary site — the rule
     * {@see \GlueAgency\Influx\models\Link::syncSiteHandles()} owns — so it
     * chips the primary site, unmarked: the site it ran against is the fact
     * worth showing, not how the config spelled it.
     *
     * Craft 5's `Cp::chipHtml()` takes any `Chippable` component, not just
     * elements, and `craft\models\Site` is one — so a site renders as a real CP
     * chip, labelled with the site's name. Craft 4 has no generic chip renderer
     * (the `Chippable` interface is the marker for one), and neither major can
     * chip a handle that no longer resolves to a site: both fall back to the
     * gray pill the overviews used before chips, carrying the same label the
     * chip would have, so nothing silently disappears.
     *
     * `autoReload` is off: Craft's chip JS re-renders a chip by asking the
     * server for the component behind it, which is wasted work for a static
     * config listing.
     */
    public static function siteChipHtml(?string $handle, array $config = []): string
    {
        $sites = Craft::$app->getSites();
        $site = ($handle === null || $handle === '')
            ? $sites->getPrimarySite()
            : $sites->getSiteByHandle($handle);

        if ($site && interface_exists(Chippable::class)) {
            return Cp::chipHtml($site, $config + ['autoReload' => false]);
        }

        return Html::tag('span', Html::encode($site?->getUiLabel() ?? $handle), [
            'class' => ['influx-pill', 'influx-pill--gray'],
        ]);
    }

    /**
     * A link's configured sites as one self-contained group, for the Links
     * overview's "Sites" cell. Exposed to Twig as `influxSiteChips()`.
     *
     * No configured sites means the primary site, chipped on its own
     * ({@see siteChipHtml()}).
     *
     * Craft 5.4+ gets Craft's own component preview for the rest: the first
     * site's chip plus a `+N` badge that swaps itself for the remaining chips
     * when clicked (`Cp::componentPreviewHtml()`), which keeps a link on a dozen
     * sites one line tall.
     *
     * Everything else — Craft 4, Craft 5.0–5.3, or a handle that no longer
     * resolves to a site — renders the whole set in a plain wrapping row so the
     * dead handle stays visible instead of hiding behind a badge that can't
     * count it. {@see siteChipHtml()} then degrades each entry on its own.
     *
     * @param string[] $handles
     */
    public static function siteChipsHtml(array $handles): string
    {
        if ($handles === []) {
            return Html::tag('div', static::siteChipHtml(null), ['class' => 'influx-pill-group']);
        }

        $sites = array_filter(array_map(
            static fn(string $handle): ?Site => Craft::$app->getSites()->getSiteByHandle($handle),
            $handles,
        ));

        if (count($sites) === count($handles) && method_exists(Cp::class, 'componentPreviewHtml')) {
            return Cp::componentPreviewHtml(array_values($sites), ['autoReload' => false]);
        }

        $chips = implode('', array_map(
            static fn(string $handle): string => static::siteChipHtml($handle),
            $handles,
        ));

        return Html::tag('div', $chips, ['class' => 'influx-pill-group']);
    }

    /**
     * Craft's own `_includes/forms/elementSelect` partial, rendered server-side
     * for a single-element picker, plus the JS settings that bring it to life.
     * The LinkBuilder SPA drops the HTML into a ref'd `<div>` and instantiates
     * `Craft.BaseElementSelectInput(jsSettings)` itself — `registerJs: false`
     * keeps the partial from emitting its own init into the page-level JS
     * register, which would never fire on a SPA load anyway.
     *
     * Lives here because it is CP rendering pinned to a Craft version on both
     * halves: the partial's variable set and `BaseElementSelectInput`'s settings
     * both moved between the majors (`showActionMenu` is a 5.x addition), and
     * `jsSettings` hand-mirrors what the partial builds for a standard CP field,
     * so the two have to stay in step. A key the running major doesn't know is
     * inert either way — Twig ignores an unused variable, and the JS class merges
     * settings over its own defaults.
     *
     * A read-only environment renders the control disabled: chips stay visible,
     * choose/remove go dead.
     *
     * `sortable` stays off whatever the picker's shape: a multi-relation default
     * is an identity set — {@see \GlueAgency\Influx\fields\Relation::parse()}
     * looks each picked id up on its own — so dragging them into an order would
     * suggest a meaning the sync doesn't carry.
     *
     * @param string $elementType FQCN of the target element type.
     * @param ElementInterface[] $elements Currently-selected elements.
     * @param array{sources?: string|string[], limit?: int|null, single?: bool} $config
     * The picker's shape, derived from the mapped field by
     * {@see \GlueAgency\Influx\services\LinkBuilderService::elementSelectConfigFor()}.
     * A null `limit` means unlimited — how Craft's own partial and
     * `BaseElementSelectInput` both read it. Omitted keys fall back to the
     * single-element-from-anywhere shape the native author row wants.
     * @return array{html: string, jsSettings: array}
     */
    public static function elementSelectInput(string $elementType, array $elements, bool $readOnly, array $config = []): array
    {
        $hostId = 'influx-el-' . StringHelper::randomString(8);

        // `+=` only fills keys the caller left out, so an explicit `limit => null`
        // (unlimited) isn't overwritten the way `??` on each value would.
        $config += ['sources' => '*', 'limit' => 1, 'single' => true];

        $shared = [
            'id'             => $hostId,
            'name'           => null,
            'elementType'    => $elementType,
            'sources'        => $config['sources'],
            'limit'          => $config['limit'],
            'single'         => $config['single'],
            'sortable'       => false,
            'showActionMenu' => false,
            'disabled'       => $readOnly,
        ];

        $html = Craft::$app->getView()->renderTemplate('_includes/forms/elementSelect', $shared + [
            'elements'   => $elements,
            'registerJs' => false,
        ]);

        return [
            'html'       => $html,
            'jsSettings' => $shared + [
                'viewMode'         => 'list',
                'defaultPlacement' => 'end',
                'modalSettings'    => (object) [],
            ],
        ];
    }

    /**
     * Craft 5 renamed CpScreenResponseBehavior::additionalButtons() to
     * additionalButtonsHtml(). hasMethod() (not method_exists()) because the
     * behavior's methods route through Yii's magic __call().
     */
    public static function additionalButtonsHtml(Response $response, ?string $html): Response
    {
        return $response->hasMethod('additionalButtonsHtml')
            ? $response->additionalButtonsHtml($html)
            : $response->additionalButtons($html);
    }

    /**
     * Cp::readOnlyNoticeHtml() is @since 5.6. The fallback replicates its
     * markup minus the icon (Cp::iconSvg() is 5.x-only).
     */
    public static function readOnlyNoticeHtml(): string
    {
        if (method_exists(Cp::class, 'readOnlyNoticeHtml')) {
            return Cp::readOnlyNoticeHtml();
        }

        return Html::tag(
            'div',
            Html::tag('p', Craft::t('app', 'Changes to these settings aren’t permitted in this environment.')),
            ['class' => 'content-notice'],
        );
    }

    /**
     * Craft 5 renamed CpScreenResponseBehavior::notice() to noticeHtml().
     */
    public static function noticeHtml(Response $response, ?string $html): Response
    {
        return $response->hasMethod('noticeHtml')
            ? $response->noticeHtml($html)
            : $response->notice($html);
    }
}
