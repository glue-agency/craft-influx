<?php

namespace GlueAgency\Influx\fields;

use craft\fields\data\JsonData;
use craft\fields\Json as CraftJsonField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\sync\FieldContext;

/**
 * JSON mapping strategy (Craft 5).
 *
 * Two things were wrong on the DefaultField fallback, one of them silent data
 * corruption:
 *
 * 1. A JSON STRING was stored as a string. Craft only decodes on the request
 *    path ({@see \craft\fields\Json::normalizeValueFromRequest()}); the plain
 *    {@see \craft\fields\Json::normalizeValue()} a programmatic write goes
 *    through wraps whatever it's given verbatim, so a feed shipping
 *    `"{\"a\":1}"` stored a JSON document whose entire value was that string.
 *    It looked right in the CP — a string of JSON renders as JSON — and read
 *    wrong from Twig. {@see parse()} decodes first.
 * 2. Key order counted. The stored side reaches a comparison as a
 *    {@see JsonData}, the incoming side as an array, and `json_encode` preserves
 *    insertion order — so a feed that re-emitted the same document with its keys
 *    in a different order rewrote the field. {@see normalize()} sorts object keys
 *    recursively.
 *
 * List order is deliberately NOT sorted: position is meaning in a JSON array, so
 * two arrays holding the same items in a different order are two different
 * documents.
 */
class Json extends Field
{
    /**
     * A Json field holds an arbitrary structure; matching one exactly is not identifying an element by it.
     * See {@see Field::matchable()}.
     */
    public static function matchable(): bool
    {
        return false;
    }

    public static function craftFieldClass(): ?string
    {
        return CraftJsonField::class;
    }

    /**
     * `resolve()` already normalises empty to null, so no extra empty guard is
     * needed. A feed's JSON has usually already been decoded by the time it gets
     * here — the feed itself is JSON — so the string branch covers the case of a
     * document embedded in a string field upstream.
     *
     * @throws MappingValueException when a present string isn't valid JSON.
     * Storing it verbatim is what produced the corrupted documents above.
     */
    public function parse(FieldContext $context): mixed
    {
        $raw = $context->mapping->resolve($context->item);

        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MappingValueException('Invalid JSON value: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Reduce both sides to a canonical encoding with object keys sorted, so the
     * same document compares equal however either side happened to order it.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof JsonData) {
            $value = $value->getValue();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return parent::normalize(is_array($value) ? self::sortKeys($value) : $value);
    }

    /**
     * Sort an object's keys, recursively, leaving list order alone.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    protected static function sortKeys(array $value): array
    {
        $sorted = [];

        foreach ($value as $key => $item) {
            $sorted[$key] = is_array($item) ? self::sortKeys($item) : $item;
        }

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
