<?php

namespace TDM\Influx\fields;

use craft\base\ElementInterface;

/**
 * Strategy for craft\htmlfield\HtmlField-based fields (Redactor, CKEditor, …).
 *
 * The base Field::hasChanged() compares via normalize(), which breaks for
 * HtmlFieldData objects — even after the Stringable fix the stored raw content
 * has already been through the field's HTML purifier while the incoming value
 * has not, so a trivial normalize() comparison produces false positives.
 *
 * This strategy compares by running the incoming value through the same
 * serializeValue() pipeline (purifier, ref-tag expansion, …) that Craft
 * uses when saving, then comparing the result against the stored raw content.
 */
class RichText extends Field
{
    public static function craftFieldClass(): ?string
    {
        return 'craft\htmlfield\HtmlField';
    }

    public function parseField(): mixed
    {
        return $this->fetchSimpleValue();
    }

    public function hasChanged(ElementInterface $element, mixed $incoming): bool
    {
        if ($incoming === null || $incoming === '') {
            return false;
        }
        try {
            $current = $element->getFieldValue($this->fieldHandle);
            $currentRaw = is_a($current, 'craft\htmlfield\HtmlFieldData')
                ? $current->getRawContent()
                : (string)($current ?? '');
            $serialized = (string)($this->craftField->serializeValue($incoming, $element) ?? '');
        } catch (\Throwable) {
            return parent::hasChanged($element, $incoming);
        }
        return $currentRaw !== $serialized;
    }
}
