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
                    'fieldMeta'   => $this->fieldMeta($field),
                ];
            }
        }

        return $fields;
    }

    /**
     * Per-field-type metadata for the typed-mapping UI. Implemented as a
     * dispatch on the field's FQCN so we don't need a separate adapter per
     * type until the divergence is genuinely big. Anything Influx doesn't
     * have an opinion about returns an empty array.
     */
    private function fieldMeta(\craft\base\FieldInterface $field): array
    {
        if ($field instanceof \craft\fields\Assets) {
            return [
                'kind'        => 'asset',
                'allowedKinds' => $field->allowedKinds ?? null,
                'subFields'   => [
                    // Each entry: handle => label. The handle is used as the
                    // sub-mapping key on the saved Link config.
                    'alt'   => Craft::t('influx', 'Alt text'),
                    'title' => Craft::t('influx', 'Title'),
                ],
            ];
        }

        if ($field instanceof \craft\fields\Dropdown
            || $field instanceof \craft\fields\RadioButtons
            || $field instanceof \craft\fields\Checkboxes
            || $field instanceof \craft\fields\MultiSelect
        ) {
            $options = [];
            foreach ($field->options ?? [] as $opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $options[(string)$opt['value']] = (string)($opt['label'] ?? $opt['value']);
                }
            }
            return ['kind' => 'options', 'options' => $options];
        }

        if ($field instanceof \craft\fields\Lightswitch) {
            return ['kind' => 'boolean'];
        }

        if ($field instanceof \craft\fields\BaseRelationField) {
            return [
                'kind'        => 'relation',
                'elementType' => $field::elementType(),
            ];
        }

        if ($field instanceof \craft\fields\Matrix) {
            return ['kind' => 'matrix']; // full Matrix UI is a later iteration
        }

        return [];
    }
}
