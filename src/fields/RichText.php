<?php

namespace GlueAgency\Influx\fields;

use GlueAgency\Influx\sync\FieldContext;
use Throwable;

/**
 * Strategy for craft\htmlfield\HtmlField-based fields (Redactor, CKEditor, …).
 *
 * The base change comparison ({@see Field::valueDiffers()}) runs through
 * normalize(), which breaks for HtmlFieldData objects — even after the
 * Stringable fix the stored raw content has already been through the field's
 * HTML purifier while the incoming value has not, so a trivial normalize()
 * comparison produces false positives.
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

    public function parse(FieldContext $context): mixed
    {
        return $context->mapping->resolve($context->item);
    }

    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        if ($context->craftField === null) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        // Keep our own inner guard: getRawContent()/serializeValue() run the
        // purifier and ref-tag expansion, which can throw on malformed HTML.
        // A failure here falls back to the base normalize() comparison rather
        // than the "assume changed" guard — a broken purifier shouldn't force
        // a needless save.
        try {
            $currentRaw = is_a($current, 'craft\htmlfield\HtmlFieldData')
                ? $current->getRawContent()
                : (string) ($current ?? '');
            $serialized = (string) ($context->craftField->serializeValue($incoming, $context->element) ?? '');
        } catch (Throwable) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        return $currentRaw !== $serialized;
    }
}
