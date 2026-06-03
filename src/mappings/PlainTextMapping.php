<?php

namespace TDM\Influx\mappings;

use craft\base\ElementInterface;
use TDM\Influx\models\Feed;

/**
 * Maps a scalar JSON node onto a plain-text destination — either a native
 * element attribute (`title`, `slug`, `postDate`) or a PlainText custom field.
 *
 * Options:
 *   trim:       bool, default true
 *   maxLength:  int, optional hard cap (silently truncates)
 *   prefix/suffix: string, optional
 */
class PlainTextMapping extends AbstractMapping
{
    public static function type(): string
    {
        return 'PlainText';
    }

    public function apply(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Feed $feed,
    ): bool {
        $value = $this->resolve($item, $config);

        if ($element->hasAttribute($targetFieldHandle) || property_exists($element, $targetFieldHandle)) {
            $element->$targetFieldHandle = $value;
        } else {
            $element->setFieldValue($targetFieldHandle, $value);
        }

        return true;
    }

    public function hasChanged(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Feed $feed,
    ): bool {
        $current = $this->currentValue($element, $targetFieldHandle);
        $incoming = $this->resolve($item, $config);

        return $this->normalize($current) !== $this->normalize($incoming);
    }

    private function resolve(array $item, array $config): ?string
    {
        $raw = $this->extract($item, $config);
        if ($raw === null) {
            return null;
        }
        $value = is_scalar($raw) ? (string)$raw : json_encode($raw);

        $options = $config['options'] ?? [];

        if ($options['trim'] ?? true) {
            $value = trim($value);
        }
        if (isset($options['prefix'])) {
            $value = $options['prefix'] . $value;
        }
        if (isset($options['suffix'])) {
            $value .= $options['suffix'];
        }
        if (isset($options['maxLength']) && is_int($options['maxLength'])) {
            $value = mb_substr($value, 0, $options['maxLength']);
        }

        return $value;
    }
}
