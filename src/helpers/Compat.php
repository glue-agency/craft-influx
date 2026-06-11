<?php

namespace GlueAgency\Influx\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\models\EntryType;
use craft\models\Section;
use yii\web\Response;

/**
 * Single seam between Influx and the Craft 4 / Craft 5 API differences.
 *
 * Every check is feature detection (class/method/property existence) rather
 * than version parsing: each branch names the exact API it papers over, and
 * point releases that backport a method automatically take the native path.
 *
 * Supported range: Craft 4.0 – 5.x.
 */
class Compat
{
    /**
     * The Sections service was removed in Craft 5 (section + entry-type
     * lookups moved to the Entries service) — its absence is the marker.
     */
    public static function isCraft5(): bool
    {
        return !class_exists(\craft\services\Sections::class);
    }

    // -- Section / entry-type lookups ----------------------------------------
    // Craft 4: Craft::$app->getSections() — Craft 5: Craft::$app->getEntries()

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
     * @return \craft\services\Entries|\craft\services\Sections
     */
    protected static function sectionsService(): object
    {
        return static::isCraft5()
            ? Craft::$app->getEntries()
            : Craft::$app->getSections();
    }

    // -- Model / element differences ------------------------------------------

    /**
     * EntryType::$showSlugField is @since 5.0 — Craft 4 entry types always
     * expose the slug attribute.
     */
    public static function entryTypeShowsSlugField(EntryType $entryType): bool
    {
        return !property_exists($entryType, 'showSlugField') || $entryType->showSlugField;
    }

    /**
     * Craft 5 entries are multi-author (setAuthorIds() @since 5.0); Craft 4
     * entries take a single author ID.
     */
    public static function setEntryAuthor(Entry $entry, int $userId): void
    {
        if (method_exists($entry, 'setAuthorIds')) {
            $entry->setAuthorIds([$userId]);
        } else {
            $entry->setAuthorId($userId);
        }
    }

    /**
     * Deletes an element's presence in a single site.
     *
     * Elements::deleteElementForSite() is @since 4.4 and always acts on the
     * site the element instance was loaded in (it takes no site argument), so
     * the element is reloaded in the target site first. The 4.0–4.3 fallback
     * replicates the core method's essentials: a full delete when the target
     * site is the element's only site, otherwise dropping the site row and
     * invalidating caches.
     */
    public static function deleteElementForSite(ElementInterface $element, int $siteId): void
    {
        $elements = Craft::$app->getElements();

        if ((int)$element->siteId !== $siteId) {
            $element = $elements->getElementById($element->id, get_class($element), $siteId);
            if (!$element) {
                // Not present in the target site — nothing to delete.
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

        if (!$existsElsewhere) {
            $elements->deleteElement($element, true);
            return;
        }

        Db::delete(CraftTable::ELEMENTS_SITES, [
            'elementId' => $element->id,
            'siteId' => $siteId,
        ]);
        $elements->invalidateCachesForElement($element);
    }

    // -- CP chrome -------------------------------------------------------------

    /**
     * Element chip HTML. Craft 5: Cp::elementChipHtml(); Craft 4:
     * Cp::elementHtml(), which has no `hyperlink` option — emulated with a
     * plain anchor wrap. Exposed to Twig as `influxElementChip()`.
     */
    public static function elementChipHtml(ElementInterface $element, array $config = []): string
    {
        if (method_exists(Cp::class, 'elementChipHtml')) {
            return Cp::elementChipHtml($element, $config);
        }

        $html = Cp::elementHtml($element);
        if (!empty($config['hyperlink'])) {
            $url = $element->getCpEditUrl();
            if ($url) {
                $html = Html::a($html, $url);
            }
        }

        return $html;
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
     * Craft 5 renamed CpScreenResponseBehavior::notice() to noticeHtml().
     */
    public static function noticeHtml(Response $response, ?string $html): Response
    {
        return $response->hasMethod('noticeHtml')
            ? $response->noticeHtml($html)
            : $response->notice($html);
    }
}
