<?php

namespace TDM\Influx\fields;

use Cake\Utility\Hash;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use TDM\Influx\models\Link;

/**
 * Per-Craft-field-type mapping strategy. One concrete subclass per `craft\fields\*`
 * class whose mapping behaviour genuinely diverges from the default; everything
 * else falls through to {@see DefaultField}.
 *
 * Mirrors FeedMe's `craft\feedme\base\Field` so the codebase reads the same to
 * anyone who already knows that plugin. We're picking up the SRP win — one
 * place per field type — without inheriting FeedMe's wider surface area
 * (templates, events, multi-element saves...) until we actually need it.
 *
 * Lifecycle, driven by {@see \TDM\Influx\services\SynchronizationService}:
 *
 *   $strategy->setContext($craftField, $handle, $fieldInfo, $item, $link, $element);
 *   $value = $strategy->parseField();
 *   if ($strategy->hasChanged($element, $value)) {
 *       $strategy->apply($element, $value);
 *   }
 *
 * `parseField()` is the one method subclasses have to implement; everything
 * else has a sensible default in this base.
 */
abstract class Field
{
    /**
     * FQCN of the Craft field class this strategy handles. Return `null` to
     * register as the generic fallback (only {@see DefaultField} should).
     *
     * Subclasses may also point at a base class (e.g. `BaseOptionsField`)
     * to cover a whole family — {@see \TDM\Influx\services\FieldsService}
     * walks the parent chain on lookup.
     */
    public static function craftFieldClass(): ?string
    {
        return null;
    }

    protected ?CraftFieldInterface $craftField = null;

    protected string $fieldHandle = '';

    /** Per-field mapping config from the link's `mappings[handle]` shape. */
    protected array $fieldInfo = [];

    /** The remote item being processed (one element of the root list). */
    protected array $item = [];

    protected ?Link $link = null;

    protected ?ElementInterface $element = null;

    /**
     * When true the strategy must be side-effect free: no element saves, no
     * asset uploads, no created-when-missing relations. Set by
     * {@see \TDM\Influx\services\DebugService} so dry-runs stay dry.
     */
    protected bool $dryRun = false;

    public function setContext(
        ?CraftFieldInterface $craftField,
        string $fieldHandle,
        array $fieldInfo,
        array $item,
        Link $link,
        ElementInterface $element,
        bool $dryRun = false,
    ): void {
        $this->craftField = $craftField;
        $this->fieldHandle = $fieldHandle;
        $this->fieldInfo = $fieldInfo;
        $this->item = $item;
        $this->link = $link;
        $this->element = $element;
        $this->dryRun = $dryRun;
    }

    /**
     * Resolve the remote item + per-field config into the value the element
     * field should hold. Return `null` to indicate "no value, leave the field
     * untouched" — the sync loop checks the result before applying.
     */
    abstract public function parseField(): mixed;

    /**
     * UI-side metadata for the mapping editor. Targets call this through
     * {@see \TDM\Influx\services\FieldsService::metaFor()} when building the
     * mappable-fields list, so per-field-type UI hints (asset sub-fields,
     * dropdown options, relation element type, ...) live next to the parse
     * logic instead of in a giant if-chain on the target.
     *
     * Subclasses override when they have something to say; the default is
     * "no extras", which is correct for plain field types.
     */
    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [];
    }

    /**
     * Whether this field type contributes a per-field options block to the
     * mapping editor (the "Configure" toggle in {@see MappingExtras.vue}).
     * Drives the twig `hasExtras` flag on the mapping row so the toggle and
     * mount point only render when the strategy actually has options to show.
     */
    public function hasMappingExtras(): bool
    {
        return false;
    }

    /**
     * UI strings shared by every kind of mapping-extras block — currently
     * just the show/hide toggle copy. Strategies layer their own labels on
     * top via `static::extrasLabels()` (or analogous) when assembling
     * {@see fieldMeta()}, so the Vue side reads everything from
     * `fieldMeta.labels` instead of hard-coding translations.
     *
     * @return array<string, string>
     */
    public static function commonExtrasLabels(): array
    {
        return [
            'configure'   => \Craft::t('influx', 'Configure'),
            'hideOptions' => \Craft::t('influx', 'Hide options'),
        ];
    }

    /**
     * Set the parsed value on the element. Default: route to `setFieldValue`,
     * which is correct for every custom field. Subclasses override only when
     * they need something more involved (e.g. assets-as-IDs arrays).
     */
    public function apply(ElementInterface $element, mixed $value): bool
    {
        $element->setFieldValue($this->fieldHandle, $value);
        return true;
    }

    /**
     * Whether the incoming value differs from what the element currently holds.
     * The sync engine uses this to skip elements that nothing has changed for.
     */
    public function hasChanged(ElementInterface $element, mixed $incoming): bool
    {
        try {
            $current = $element->getFieldValue($this->fieldHandle);
        } catch (\Throwable) {
            return true;
        }
        return $this->normalize($current) !== $this->normalize($incoming);
    }

    // -- shared helpers ----------------------------------------------------

    /**
     * Read a scalar value from the remote item, falling back to the per-field
     * `default`. Returns `null` when neither side has data.
     */
    protected function fetchSimpleValue(): mixed
    {
        $node = $this->fieldInfo['node'] ?? null;
        if ($node === null || $node === '') {
            return $this->fieldInfo['default'] ?? null;
        }

        $raw = Hash::get($this->item, $node);
        if ($raw === null || $raw === '') {
            return $this->fieldInfo['default'] ?? null;
        }

        return $raw;
    }

    /**
     * Project-config-friendly representation used to compare values for change
     * detection. Two semantically-equal values should produce the same string.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if ($value instanceof \Stringable) {
            $str = (string)$value;
            return $str === '' ? null : $str;
        }
        return json_encode($value);
    }
}
