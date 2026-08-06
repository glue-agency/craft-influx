<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\data\LinkData;
use craft\fields\Link as CraftLinkField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for Craft 5.3+'s Link field — and, on Craft 5, for the
 * deprecated URL field too: `craft\fields\Url` is `class_alias`'d onto this
 * class, so a URL field IS a Link field there. (On Craft 4 it's a genuine
 * string field, which {@see DefaultField} serves correctly.)
 *
 * A Link is not one value but several: a type, the value that type addresses,
 * an optional label, and whichever advanced HTML attributes the field enables.
 * The DefaultField fallback could only ever write the first — a bare URL landed,
 * because Craft's own {@see \craft\fields\Link::normalizeValue()} treats a plain
 * string as a URL-type link — and everything else was unreachable: no way to
 * feed an entry link, a label, or a `target`.
 *
 * So the row is node-less like {@see Table}'s and {@see ContentBlock}'s, with one
 * sub-mapping per slot, and {@see parse()} assembles the array envelope Craft
 * consumes. Which slots exist is the FIELD's business, not the feed's: the type
 * select offers only the link types it allows, the label row appears only when
 * `showLabelField` is on, and the advanced rows mirror its `advancedFields`.
 *
 * The type is a sub-row rather than a mapping-level option so a feed that ships
 * a mixed list (some URLs, some entry references) can map it to a node, while a
 * feed that only ever sends URLs just picks the default once.
 *
 * v1 boundary: an element-typed link (`entry`, `asset`, `category`) takes the
 * element's ID as its value. Matching a title or slug the way the relational
 * strategies do ({@see Relation}) is deliberately not built here — a link
 * addresses one element, so it would need the whole match-by apparatus for a
 * single lookup.
 */
class Link extends Field
{
    /** The link-type slot; a value Craft doesn't know throws rather than silently retyping the link. */
    protected const TYPE_HANDLE = 'type';

    /** The slot carrying what the type addresses — a URL, an email, an element ID. */
    protected const VALUE_HANDLE = 'value';

    /** Offered only when the field has its label field switched on. */
    protected const LABEL_HANDLE = 'label';

    /** The one advanced slot that is a flag rather than text. */
    protected const DOWNLOAD_HANDLE = 'download';

    /** Craft's own fallback when a field declares no types ({@see \craft\fields\linktypes\Url::id()}). */
    protected const DEFAULT_TYPE = 'url';

    public static function craftFieldClass(): ?string
    {
        return CraftLinkField::class;
    }

    /**
     * One always-visible card: the type and value every link needs, then the
     * optional slots this particular field turns on. The type row is a select
     * over the field's own allowed types — a closed set the operator shouldn't
     * have to retype — defaulted to the first one it allows.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            // The value derives entirely from the sub-mappings below, so the row
            // renders neither cell of its own — absence is the whole declaration.
            'source'  => false,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) use ($field) {
                $typeIds = $this->linkTypeIds($field);

                $subFields = MappingSchemaBuilder::make()
                    ->select([
                        'handle'  => self::TYPE_HANDLE,
                        'label'   => Craft::t('influx', 'Link type'),
                        'options' => $this->typeOptions($field),
                        'default' => $typeIds[0] ?? self::DEFAULT_TYPE,
                    ])
                    ->text([
                        'handle' => self::VALUE_HANDLE,
                        'label'  => Craft::t('influx', 'Value'),
                    ]);

                if ($this->showsLabelField($field)) {
                    $subFields->text([
                        'handle' => self::LABEL_HANDLE,
                        'label'  => Craft::t('influx', 'Label'),
                    ]);
                }

                foreach ($this->advancedHandles($field) as $handle) {
                    $config = [
                        'handle' => $handle,
                        'label'  => $this->advancedLabel($handle),
                    ];

                    if ($handle === self::DOWNLOAD_HANDLE) {
                        $subFields->lightswitch($config);

                        continue;
                    }

                    $subFields->text($config);
                }

                return $b->subFields([
                    'label'     => Craft::t('influx', 'Link'),
                    'subFields' => $subFields->toArray(),
                ]);
            },
        ]);
    }


    /**
     * A node-less row is addressed through its sub-mappings, never its own
     * (absent) node — so it's addressed when ANY active one is addressed for this
     * item. A link whose slots are all unaddressed leaves the field untouched.
     */
    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the array envelope Craft consumes. Craft filters the advanced keys
     * against the field's own settings on the way in, so writing one the field
     * has since switched off is harmless — no need to re-check here beyond
     * knowing which slots the mapping was allowed to declare.
     *
     * A link with no value is no link: null clears the field, which is the right
     * answer for an addressed row whose value resolved to nothing (the feed is
     * authoritative). Slots other than the value can't resurrect it — a label
     * with nothing to label is not a link.
     *
     * @throws MappingValueException when the feed names a link type the field
     * doesn't allow. Craft throws `InvalidArgumentException` for an unknown type,
     * which would fail the whole item; catching it here fails just this row.
     *
     * @return array<string, mixed>|null
     */
    public function parse(FieldContext $context): mixed
    {
        $allowed = $this->mappableHandles($context->craftField);
        $values = [];

        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if (! in_array($sub->handle, $allowed, true)) {
                continue;
            }

            $values[$sub->handle] = $sub->resolve($context->item);
        }

        $value = $values[self::VALUE_HANDLE] ?? null;
        $value = is_scalar($value) ? trim((string) $value) : $value;

        if ($value === null || $value === '') {
            return null;
        }

        $link = [
            self::TYPE_HANDLE  => $this->resolveType($context, $values[self::TYPE_HANDLE] ?? null),
            self::VALUE_HANDLE => $value,
        ];

        foreach ($values as $handle => $raw) {
            if ($handle === self::TYPE_HANDLE || $handle === self::VALUE_HANDLE || $raw === null) {
                continue;
            }

            $link[$handle] = $handle === self::DOWNLOAD_HANDLE ? Lightswitch::coerce($raw) : $raw;
        }

        return $link;
    }

    /**
     * Reduce both sides to the same canonical map so an unchanged feed doesn't
     * rewrite the field. The stored side is a {@see LinkData} at the top level
     * and its already-serialized array inside a nested fingerprint; the incoming
     * side is what {@see parse()} just built. `LinkData::serialize()` emits
     * exactly the envelope `normalizeValue()` accepts, which is what makes one
     * shared reduction possible at all.
     *
     * Both sides are filtered the way Craft filters its own serialization, so an
     * advanced key the field doesn't write compares equal to one the feed left
     * empty — otherwise every unmapped attribute would read as a change.
     */
    protected function normalize(mixed $value): mixed
    {
        $link = $this->linkArray($value);

        if ($link === []) {
            return null;
        }

        ksort($link);

        return parent::normalize($link);
    }

    /**
     * A link in its comparable map form, dropping the empty keys Craft's own
     * `array_filter()` drops on serialization. Anything that isn't a link — a
     * bare string a mapping wrote before this strategy existed, say — reduces to
     * no link, which is what it is.
     *
     * @return array<string, mixed>
     */
    protected function linkArray(mixed $value): array
    {
        if ($value instanceof LinkData) {
            $value = $value->serialize();
        }

        if (! is_array($value)) {
            return [];
        }

        return array_filter(
            $value,
            fn(mixed $item): bool => $item !== null && $item !== '' && $item !== false,
        );
    }

    /**
     * The link type for one item: the feed's, validated against what the field
     * allows, or the field's first type when the feed says nothing. Matching is
     * trimmed and lowercased because a type id is a lowercase slug and a feed
     * spelling it `URL` means the same thing.
     *
     * @throws MappingValueException
     */
    protected function resolveType(FieldContext $context, mixed $raw): string
    {
        $typeIds = $this->linkTypeIds($context->craftField);
        $type = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';

        if ($type === '') {
            return $typeIds[0] ?? self::DEFAULT_TYPE;
        }

        if ($typeIds !== [] && ! in_array($type, $typeIds, true)) {
            throw new MappingValueException("Unsupported link type '{$type}' for this field.");
        }

        return $type;
    }

    /**
     * Every slot this field offers — the gate {@see parse()} filters stored
     * sub-mappings through, so a mapping that outlived a setting being switched
     * off is skipped rather than written.
     *
     * @return list<string>
     */
    protected function mappableHandles(?CraftFieldInterface $field): array
    {
        $handles = [self::TYPE_HANDLE, self::VALUE_HANDLE];

        if ($this->showsLabelField($field)) {
            $handles[] = self::LABEL_HANDLE;
        }

        return array_merge($handles, $this->advancedHandles($field));
    }

    /**
     * The field's allowed link type ids, in its own configured order — the first
     * doubles as the default. Extracted so the no-boot tests can drive parse()
     * without a real Link field.
     *
     * @return list<string>
     */
    protected function linkTypeIds(?CraftFieldInterface $field): array
    {
        if (! $field instanceof CraftLinkField) {
            return [];
        }

        return array_map('strval', array_keys($field->getLinkTypes()));
    }

    /**
     * The type select's rows, labelled with each link type's own display name.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function typeOptions(?CraftFieldInterface $field): array
    {
        if (! $field instanceof CraftLinkField) {
            return [];
        }

        $options = [];

        foreach ($field->getLinkTypes() as $typeId => $linkType) {
            $options[] = [
                'value' => (string) $typeId,
                'label' => $linkType::displayName(),
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    protected function advancedHandles(?CraftFieldInterface $field): array
    {
        if (! $field instanceof CraftLinkField) {
            return [];
        }

        return array_values(array_map('strval', $field->advancedFields));
    }

    protected function showsLabelField(?CraftFieldInterface $field): bool
    {
        return $field instanceof CraftLinkField && $field->showLabelField;
    }

    /**
     * Craft names these settings but never exposes a label for one on its own, so
     * the mapping UI supplies them. An unrecognised handle — a future advanced
     * field Craft adds — degrades to the handle itself rather than an empty row.
     */
    protected function advancedLabel(string $handle): string
    {
        return match ($handle) {
            'urlSuffix'           => Craft::t('influx', 'URL suffix'),
            'target'              => Craft::t('influx', 'Target'),
            'title'               => Craft::t('influx', 'Title'),
            'class'               => Craft::t('influx', 'Class'),
            'id'                  => Craft::t('influx', 'ID'),
            'rel'                 => Craft::t('influx', 'Rel'),
            'ariaLabel'           => Craft::t('influx', 'ARIA label'),
            self::DOWNLOAD_HANDLE => Craft::t('influx', 'Download'),
            default               => $handle,
        };
    }

    /**
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $mapping): array
    {
        return $this->filterActive($mapping->subMappings());
    }
}
