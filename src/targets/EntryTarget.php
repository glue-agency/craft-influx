<?php

namespace TDM\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\Link;

/**
 * Default target for craft\elements\Entry.
 *
 * Recognized elementCriteria keys:
 *   section: handle of the section (required for new entries)
 *   type:    handle of the entry type (required for new entries)
 *   author:  id or username of the default author (optional)
 */
class EntryTarget extends AbstractElementTarget
{
    public static function elementType(): string
    {
        return Entry::class;
    }

    public function claimsElement(Link $link, ElementInterface $element): bool
    {
        if (!($element instanceof Entry)) {
            return false;
        }

        if (!$this->handles($link)) {
            return false;
        }

        $sectionHandle = $link->elementCriteria['section'] ?? null;
        if ($sectionHandle && $element->getSection()?->handle !== $sectionHandle) {
            return false;
        }

        $typeHandle = $link->elementCriteria['type'] ?? null;
        if ($typeHandle && $element->getType()?->handle !== $typeHandle) {
            return false;
        }

        $matchAttr = $link->matchAttribute();
        if (!$matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Entry
    {
        $matchAttr = $link->matchAttribute();
        if (!$matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Entry::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        if (isset($link->elementCriteria['section'])) {
            $query->section($link->elementCriteria['section']);
        }
        if (isset($link->elementCriteria['type'])) {
            $query->type($link->elementCriteria['type']);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query->one();
    }

    public function buildNew(Link $link, ?int $siteId = null): Entry
    {
        $sectionHandle = $link->elementCriteria['section']
            ?? throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.section for Entry targets.",
            );

        // Craft 5: sections moved to the Entries service.
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)
            ?? throw new InfluxException("Section '{$sectionHandle}' does not exist.");

        $typeHandle = $link->elementCriteria['type'] ?? null;

        // Craft 5: entry types are global. Resolve by handle, but make sure
        // the chosen type is actually attached to the configured section.
        $sectionEntryTypes = $section->getEntryTypes();
        $entryType = null;
        if ($typeHandle) {
            foreach ($sectionEntryTypes as $candidate) {
                if ($candidate->handle === $typeHandle) {
                    $entryType = $candidate;
                    break;
                }
            }
            if (!$entryType) {
                throw new InfluxException(
                    "Entry type '{$typeHandle}' is not attached to section '{$sectionHandle}'.",
                );
            }
        } else {
            $entryType = $sectionEntryTypes[0] ?? null;
        }

        if (!$entryType) {
            throw new InfluxException("Section '{$sectionHandle}' has no usable entry type.");
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;

        if (isset($link->elementCriteria['author'])) {
            $author = is_numeric($link->elementCriteria['author'])
                ? Craft::$app->getUsers()->getUserById((int)$link->elementCriteria['author'])
                : Craft::$app->getUsers()->getUserByUsernameOrEmail($link->elementCriteria['author']);
            if ($author) {
                $entry->setAuthorIds([$author->id]);
            }
        }

        if ($siteId) {
            $entry->siteId = $siteId;
        }

        return $entry;
    }
}
