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

        $defaultAuthorId = $link->mappings['author']['default'] ?? null;
        if ($defaultAuthorId) {
            $author = is_numeric($defaultAuthorId)
                ? Craft::$app->getUsers()->getUserById((int)$defaultAuthorId)
                : Craft::$app->getUsers()->getUserByUsernameOrEmail((string)$defaultAuthorId);
            if ($author) {
                $entry->setAuthorIds([$author->id]);
            }
        }

        if ($siteId) {
            $entry->siteId = $siteId;
        }

        return $entry;
    }

    public function getMappableFields(Link $link): array
    {
        $fields = [
            ['handle' => 'title',      'name' => Craft::t('app', 'Title'),       'native' => true, 'defaultType' => 'text'],
            ['handle' => 'slug',       'name' => Craft::t('app', 'Slug'),        'native' => true, 'defaultType' => 'text'],
            ['handle' => 'enabled',    'name' => Craft::t('app', 'Enabled'),     'native' => true, 'defaultType' => 'text'],
            ['handle' => 'postDate',   'name' => Craft::t('app', 'Post Date'),   'native' => true, 'defaultType' => 'text'],
            ['handle' => 'expiryDate', 'name' => Craft::t('app', 'Expiry Date'), 'native' => true, 'defaultType' => 'text'],
            ['handle' => 'author',     'name' => Craft::t('app', 'Author'),      'native' => true, 'defaultType' => 'user'],
        ];

        $sectionHandle = $link->elementCriteria['section'] ?? null;
        $typeHandle    = $link->elementCriteria['type'] ?? null;
        if (!$sectionHandle) {
            return $fields;
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            return $fields;
        }

        $entryTypes = $section->getEntryTypes();
        $entryType = null;
        if ($typeHandle) {
            foreach ($entryTypes as $candidate) {
                if ($candidate->handle === $typeHandle) {
                    $entryType = $candidate;
                    break;
                }
            }
        }
        $entryType ??= $entryTypes[0] ?? null;
        if (!$entryType) {
            return $fields;
        }

        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            return $fields;
        }

        foreach ($layout->getCustomFields() as $field) {
            $fields[] = [
                'handle'      => $field->handle,
                'name'        => $field->name,
                'native'      => false,
                'defaultType' => 'text',
            ];
        }

        return $fields;
    }
}
