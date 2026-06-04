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

    /**
     * `author` is read from the mapping's `default` during {@see buildNew()}
     * and applied as a user id — the generic dispatcher must not also try to
     * assign it as a plain string.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return $handle === 'author';
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

    protected function parsePostDate(\craft\base\ElementInterface $element, array $item, array $config): bool
    {
        return $this->assignDate($element, 'postDate', $item, $config);
    }

    protected function parseExpiryDate(\craft\base\ElementInterface $element, array $item, array $config): bool
    {
        return $this->assignDate($element, 'expiryDate', $item, $config);
    }

    private function assignDate(\craft\base\ElementInterface $element, string $attr, array $item, array $config): bool
    {
        $value = $this->resolveValue($item, $config);
        if ($value === null || $value === '') {
            return false;
        }
        if ($value instanceof \DateTimeInterface) {
            $element->{$attr} = $value instanceof \DateTime ? $value : \DateTime::createFromInterface($value);
            return true;
        }
        $parsed = \craft\helpers\DateTimeHelper::toDateTime($value);
        if (!$parsed) {
            return false;
        }
        $element->{$attr} = $parsed;
        return true;
    }

    /**
     * Translate the `status` mapping to the underlying `enabled` flag (status
     * is a derived attribute computed from enabled + postDate + expiryDate;
     * the sync engine can't set it directly).
     */
    protected function parseStatus(\craft\base\ElementInterface $element, array $item, array $config): bool
    {
        $value = $this->resolveValue($item, $config);
        if ($value === null) {
            return false;
        }
        $element->enabled = !in_array(strtolower((string)$value), ['disabled', 'false', '0'], true);
        return true;
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
        $native = Craft::t('influx', 'Native');
        $fields = [
            ['handle' => 'title', 'name' => Craft::t('app', 'Title'), 'native' => true, 'group' => $native, 'defaultType' => 'text'],
            ['handle' => 'slug',  'name' => Craft::t('app', 'Slug'),  'native' => true, 'group' => $native, 'defaultType' => 'text'],
            [
                'handle' => 'status',
                'name'   => Craft::t('app', 'Status'),
                'native' => true,
                'group'  => $native,
                'defaultType' => 'select',
                'options' => [
                    Entry::STATUS_LIVE    => Craft::t('app', 'Live'),
                    Entry::STATUS_PENDING => Craft::t('app', 'Pending'),
                    Entry::STATUS_EXPIRED => Craft::t('app', 'Expired'),
                    'disabled'            => Craft::t('app', 'Disabled'),
                ],
            ],
            ['handle' => 'postDate',   'name' => Craft::t('app', 'Post Date'),   'native' => true, 'group' => $native, 'defaultType' => 'text'],
            ['handle' => 'expiryDate', 'name' => Craft::t('app', 'Expiry Date'), 'native' => true, 'group' => $native, 'defaultType' => 'text'],
            [
                'handle' => 'author',
                'name'   => Craft::t('app', 'Author'),
                'native' => true,
                'group'  => $native,
                'defaultType' => 'element',
                'elementType' => \craft\elements\User::class,
            ],
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

        // Walk the field-layout tabs so custom fields keep the same grouping
        // they have in Craft's own entry editor. CustomField elements have a
        // `field` property; tabs without a name fall back to a generic label.
        $fallbackTab = Craft::t('influx', 'Content');
        foreach ($layout->getTabs() as $tab) {
            $tabName = $tab->name ?: $fallbackTab;
            foreach ($tab->getElements() as $element) {
                if (!($element instanceof \craft\fieldlayoutelements\CustomField)) {
                    continue;
                }
                $field = $element->getField();
                if (!$field) {
                    continue;
                }
                $fields[] = [
                    'handle'      => $field->handle,
                    'name'        => $field->name,
                    'native'      => false,
                    'group'       => $tabName,
                    'defaultType' => 'text',
                    'fieldClass'  => $field::class,
                    'fieldMeta'   => \TDM\Influx\Influx::getInstance()->fields->metaFor($field),
                ];
            }
        }

        return $fields;
    }
}
