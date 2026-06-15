<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use DateTime;
use DateTimeInterface;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Relation;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\targets\support\EntryTypeResolver;

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
        if (! ($element instanceof Entry)) {
            return false;
        }

        if (! $this->handles($link)) {
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

        if (! $matchAttr) {
            return false;
        }

        return $element->{$matchAttr} !== null && $element->{$matchAttr} !== '';
    }

    /**
     * No native author short-circuit anymore — {@see parseAuthor()} resolves
     * the value (node, falling back to `default`) through the configured
     * match strategy and sets `authorIds` itself.
     */
    public function ownsAttribute(Link $link, string $handle): bool
    {
        return false;
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Entry
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
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
        [$section, $entryType] = (new EntryTypeResolver())->resolve($link);

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;

        if ($siteId) {
            $entry->siteId = $siteId;
        }

        return $entry;
    }

    /**
     * Adds slug/title on top of the base `id` — but only when the link's
     * resolved entry type actually enables them (title fields can be
     * generated via titleFormat, slug fields hidden per type). Unresolved
     * criteria fall back to id-only.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        $attributes = parent::matchableNativeAttributes($link);

        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return $attributes;
        }
        [, $entryType] = $resolved;

        if (Compat::entryTypeShowsSlugField($entryType)) {
            $attributes[] = ['value' => 'slug', 'label' => Craft::t('influx', 'Slug (slug)')];
        }

        if ($entryType->hasTitleField) {
            // The title's label is user-editable in the entry type's field
            // layout — surface what the editor actually sees. label()
            // handles the custom value (site-translated) and the default.
            $titleElement = $entryType->getFieldLayout()?->getFirstElementByType(
                EntryTitleField::class,
            );
            $titleLabel = $titleElement?->label() ?: Craft::t('app', 'Title');
            $attributes[] = ['value' => 'title', 'label' => "{$titleLabel} (title)"];
        }

        return $attributes;
    }

    public function getMappableFields(Link $link): array
    {
        $fields = $this->nativeFieldDefinitions();

        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return $fields;
        }
        [, $entryType] = $resolved;

        $layout = $entryType->getFieldLayout();

        if (! $layout) {
            return $fields;
        }

        // Walk the field-layout tabs so custom fields keep the same grouping
        // they have in Craft's own entry editor. CustomField elements have a
        // `field` property; tabs without a name fall back to a generic label.
        $fallbackTab = Craft::t('influx', 'Content');

        foreach ($layout->getTabs() as $tab) {
            $tabName = $tab->name ?: $fallbackTab;

            foreach ($tab->getElements() as $element) {
                if (! ($element instanceof CustomField)) {
                    continue;
                }
                $field = $element->getField();

                if (! $field) {
                    continue;
                }
                $fields[] = [
                    'handle'      => $field->handle,
                    'name'        => $field->name,
                    'native'      => false,
                    'group'       => $tabName,
                    'defaultType' => 'text',
                    'fieldClass'  => $field::class,
                    'fieldMeta'   => Influx::getInstance()->fields->metaFor($field),
                ];
            }
        }

        return $fields;
    }

    // -- native attribute parsers (dispatched by handle) ---------------------

    /**
     * Feed titles routinely overflow Craft's 255-char title column —
     * truncate safely instead of letting the save fail. Mirrors feed-me's
     * title hygiene.
     */
    protected function parseTitle(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null) {
            return false;
        }

        $element->title = StringHelper::safeTruncate((string) $value, 255);

        return true;
    }

    /**
     * Slugs straight from a feed are rarely slug-safe — normalize the same
     * way Craft does when auto-generating (respects limitAutoSlugsToAscii
     * and allowUppercaseInSlug).
     */
    protected function parseSlug(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null) {
            return false;
        }

        $element->slug = ElementHelper::normalizeSlug((string) $value);

        return true;
    }

    /**
     * Resolve the per-item author through the same match strategy the
     * relational Users field uses (id / username / email / custom field),
     * then assign as `authorIds`. Falls back to the mapping's `default` (a
     * user-id picked via elementSelect) when no node value is present.
     */
    protected function parseAuthor(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null) {
            return false;
        }

        $match = (string) $mapping->option('match', 'id');
        $user = $this->findUser($match, $value);

        if (! $user) {
            return false;
        }

        /** @var Entry $element */
        Compat::setEntryAuthor($element, $user->id);

        return true;
    }

    protected function parsePostDate(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignDate($element, 'postDate', $item, $mapping);
    }

    protected function parseExpiryDate(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        return $this->assignDate($element, 'expiryDate', $item, $mapping);
    }

    /**
     * Coerce the mapped value into the `enabled` flag. (`status` itself is
     * derived by Craft from enabled + postDate + expiryDate and can't be set
     * directly — that's why the native mappable is `enabled`, not `status`.)
     * Truthy spellings follow the Lightswitch field strategy.
     */
    protected function parseEnabled(ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            $element->enabled = $value;

            return true;
        }

        $element->enabled = in_array(strtolower(trim((string) $value)), Lightswitch::TRUTHY_VALUES, true);

        return true;
    }

    protected function assignDate(ElementInterface $element, string $attr, RemoteItem $item, FieldMapping $mapping): bool
    {
        $value = $mapping->resolve($item);

        if ($value === null || $value === '') {
            return false;
        }

        if ($value instanceof DateTimeInterface) {
            $element->{$attr} = $value instanceof DateTime ? $value : DateTime::createFromInterface($value);

            return true;
        }

        // An explicit format wins over the auto-detector — feeds that ship
        // ambiguous strings (e.g. `02/03/2024`) need to disambiguate manually.
        // `timestamp` is a UI sentinel for Unix seconds (translated to the
        // PHP `U` token here so the Vue side stays human-readable).
        $format = $mapping->option('format');

        if (is_string($format) && $format !== '') {
            $phpFormat = $format === 'timestamp' ? 'U' : $format;
            $parsed = DateTime::createFromFormat($phpFormat, (string) $value);

            if ($parsed === false) {
                return false;
            }
            $element->{$attr} = $parsed;

            return true;
        }

        $parsed = DateTimeHelper::toDateTime($value);

        if (! $parsed) {
            return false;
        }
        $element->{$attr} = $parsed;

        return true;
    }

    protected function findUser(string $match, mixed $value): ?User
    {
        $query = User::find()->status(null);
        match ($match) {
            'id'       => $query->id((int) $value),
            'username' => $query->username((string) $value),
            'email'    => $query->email((string) $value),
            default    => $query->$match($value),
        };

        return $query->one();
    }

    // -- mappable-field metadata ----------------------------------------------

    /**
     * The Entry-native mappable attributes — the fixed part of
     * {@see getMappableFields()}, independent of any section/type criteria.
     *
     * @return list<array>
     */
    protected function nativeFieldDefinitions(): array
    {
        $native = Craft::t('influx', 'Native');

        return [
            ['handle' => 'title', 'name' => Craft::t('app', 'Title'), 'native' => true, 'group' => $native, 'defaultType' => 'text'],
            ['handle' => 'slug',  'name' => Craft::t('app', 'Slug'),  'native' => true, 'group' => $native, 'defaultType' => 'text'],
            [
                'handle'      => 'enabled',
                'name'        => Craft::t('app', 'Enabled'),
                'native'      => true,
                'group'       => $native,
                'defaultType' => 'select',
                'options'     => [
                    'true'  => Craft::t('app', 'Enabled'),
                    'false' => Craft::t('app', 'Disabled'),
                ],
            ],
            [
                'handle'    => 'postDate', 'name' => Craft::t('app', 'Post Date'),
                'native'    => true, 'group' => $native, 'defaultType' => 'text',
                'fieldMeta' => $this->dateFieldMeta(),
            ],
            [
                'handle'    => 'expiryDate', 'name' => Craft::t('app', 'Expiry Date'),
                'native'    => true, 'group' => $native, 'defaultType' => 'text',
                'fieldMeta' => $this->dateFieldMeta(),
            ],
            [
                'handle'      => 'author',
                'name'        => Craft::t('app', 'Author'),
                'native'      => true,
                'group'       => $native,
                'defaultType' => 'element',
                'elementType' => User::class,
                'fieldMeta'   => [
                    'kind'         => 'relation',
                    'elementType'  => User::class,
                    'matchOptions' => $this->authorMatchOptions(),
                    'allowCreate'  => false,
                    'schema'       => [
                        BuilderSchema::select('match', Craft::t('influx', 'Match by'), $this->authorMatchOptions(), [
                            'default' => 'id',
                        ]),
                    ],
                    'labels' => Relation::extrasLabels()
                        + Field::commonExtrasLabels(),
                ],
            ],
        ];
    }

    /**
     * Shared meta for postDate/expiryDate so the date extras block reads
     * its preset list and labels from {@see \GlueAgency\Influx\fields\Date}, same
     * as the custom Date field strategy.
     *
     * @return array<string, mixed>
     */
    protected function dateFieldMeta(): array
    {
        return [
            'kind'          => 'date',
            'formatOptions' => Date::formatOptions(),
            'schema'        => [
                BuilderSchema::select('format', Craft::t('influx', 'Date format'), Date::formatOptions(), [
                    'instructions' => Craft::t('influx', 'Used by DateTime::createFromFormat. "Unix timestamp" parses integer seconds; "Auto-detect" uses the Craft DateTimeHelper.'),
                    'default'      => '',
                ]),
            ],
            'labels' => Date::extrasLabels()
                + Field::commonExtrasLabels(),
        ];
    }

    /**
     * Match-by options for the native author dropdown. Built statically (no
     * Craft field instance to introspect) — id/username/email cover the
     * native identifiers, then any custom fields on the global User layout
     * are surfaced so unique handles like an external `importId` can match.
     *
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    protected function authorMatchOptions(): array
    {
        $groups = [
            [
                'label'   => Craft::t('influx', 'User'),
                'kind'    => 'element',
                'options' => [
                    ['value' => 'id',       'label' => Craft::t('influx', 'ID (id)')],
                    ['value' => 'username', 'label' => Craft::t('influx', 'Username (username)')],
                    ['value' => 'email',    'label' => Craft::t('influx', 'Email (email)')],
                ],
            ],
        ];

        $layout = Craft::$app->getFields()->getLayoutByType(User::class);
        $customFields = [];

        if ($layout) {
            foreach ($layout->getCustomFields() as $customField) {
                $customFields[] = [
                    'value' => $customField->handle,
                    'label' => $customField->name . ' (' . $customField->handle . ')',
                ];
            }
        }

        if ($customFields) {
            $groups[] = ['label' => Craft::t('influx', 'Fields'), 'kind' => 'fields', 'options' => $customFields];
        }

        return $groups;
    }
}
