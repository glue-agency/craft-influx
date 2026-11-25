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

    /**
     * `getRawContent()` / `serializeValue()` run the field's purifier and can
     * throw on bad HTML; on failure fall back to the base normalize()
     * comparison rather than assuming changed.
     *
     * Both sides are trimmed: saving drops surrounding whitespace, so a feed
     * whose HTML ends in a newline can never equal what the field ends up
     * storing, and the mapping would read as changed on every sync over that
     * one byte. Leading and trailing whitespace carries no meaning in rendered
     * HTML, so trimming can't mask a real edit.
     */
    protected function valueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
    {
        if ($context->craftField === null) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        try {
            $currentRaw = is_a($current, 'craft\htmlfield\HtmlFieldData')
                ? $current->getRawContent()
                : (string) ($current ?? '');
            $serialized = (string) ($context->craftField->serializeValue($incoming, $context->element) ?? '');
        } catch (Throwable) {
            return parent::valueDiffers($context, $current, $incoming);
        }

        return static::comparableHtml($currentRaw) !== static::comparableHtml($serialized);
    }

    /**
     * HTML as it compares: trimmed, and every reference tag without its
     * `||fallback`.
     *
     * Saving turns a URL that points at an element (an asset, an entry) into a
     * reference tag, `{asset:12:url||https://…/file.png}`, keeping the URL as the
     * fallback Craft shows when the element is gone. Serializing a stored value
     * again rewrites that fallback with the element's current URL, which can
     * differ from the feed's (a `?v=` cache-buster when `revAssetUrls` is on). And
     * Craft does serialize it again: saving an element in one site re-saves its
     * other sites from their current values. So saving the English site of an
     * entry changed the stored Dutch HTML, and the next Dutch sync read it as
     * changed and saved, and revised, the entry for nothing. Which element a tag
     * points at still counts; only its fallback text doesn't, and that never
     * renders while the element exists.
     */
    public static function comparableHtml(string $html): string
    {
        return trim((string) preg_replace('/\{([\w\\\\]+:[^{}|]+)\|\|[^{}]*\}/', '{$1}', $html));
    }
}
