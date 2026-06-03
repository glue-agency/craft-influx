<?php

namespace TDM\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use TDM\Influx\exceptions\InfluxException;
use TDM\Influx\models\Feed;

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

    public function claimsElement(Feed $feed, ElementInterface $element): bool
    {
        if (!($element instanceof Entry)) {
            return false;
        }

        if (!$this->handles($feed)) {
            return false;
        }

        $sectionHandle = $feed->elementCriteria['section'] ?? null;
        if ($sectionHandle && $element->getSection()?->handle !== $sectionHandle) {
            return false;
        }

        $typeHandle = $feed->elementCriteria['type'] ?? null;
        if ($typeHandle && $element->getType()?->handle !== $typeHandle) {
            return false;
        }

        $matchAttr = $feed->matchAttribute();
        if (!$matchAttr) {
            return false;
        }

        // The element must already carry a match-attribute value to be
        // considered claimed by this feed.
        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
    }

    public function findByMatchValue(Feed $feed, mixed $matchValue, ?int $siteId = null): ?Entry
    {
        $matchAttr = $feed->matchAttribute();
        if (!$matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Entry::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        if (isset($feed->elementCriteria['section'])) {
            $query->section($feed->elementCriteria['section']);
        }
        if (isset($feed->elementCriteria['type'])) {
            $query->type($feed->elementCriteria['type']);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query->one();
    }

    public function buildNew(Feed $feed, ?int $siteId = null): Entry
    {
        $sectionHandle = $feed->elementCriteria['section']
            ?? throw new InfluxException(
                "Feed '{$feed->handle}' must declare elementCriteria.section for Entry targets."
            );

        // Craft 5: sections moved to the Entries service.
        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle)
            ?? throw new InfluxException("Section '{$sectionHandle}' does not exist.");

        $typeHandle = $feed->elementCriteria['type'] ?? null;

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
                    "Entry type '{$typeHandle}' is not attached to section '{$sectionHandle}'."
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

        if (isset($feed->elementCriteria['author'])) {
            $author = is_numeric($feed->elementCriteria['author'])
                ? Craft::$app->getUsers()->getUserById((int)$feed->elementCriteria['author'])
                : Craft::$app->getUsers()->getUserByUsernameOrEmail($feed->elementCriteria['author']);
            if ($author) {
                // Craft 5 supports multi-author entries; setAuthorIds is the
                // canonical setter.
                $entry->setAuthorIds([$author->id]);
            }
        }

        if ($siteId) {
            $entry->siteId = $siteId;
        }

        return $entry;
    }
}
