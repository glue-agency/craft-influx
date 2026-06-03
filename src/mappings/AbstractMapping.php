<?php

namespace TDM\Influx\mappings;

use Cake\Utility\Hash;
use craft\base\ElementInterface;
use TDM\Influx\models\Feed;

abstract class AbstractMapping implements MappingInterface
{
    /**
     * Pull the configured value out of an item.
     */
    protected function extract(array $item, array $config): mixed
    {
        $node = $config['node'] ?? null;
        if ($node === null) {
            return $config['default'] ?? null;
        }
        return Hash::get($item, $node) ?? ($config['default'] ?? null);
    }

    /**
     * Default change detection: compare scalar-cast current and incoming
     * values. Most simple mappings (PlainText, Number, Lightswitch, Date) can
     * rely on this; relation/structured mappings should override.
     */
    public function hasChanged(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Feed $feed,
    ): bool {
        $current = $this->currentValue($element, $targetFieldHandle);
        $incoming = $this->extract($item, $config);

        return $this->normalize($current) !== $this->normalize($incoming);
    }

    /**
     * Current value of $targetFieldHandle on the element. Prefers attribute,
     * falls back to custom field value.
     */
    protected function currentValue(ElementInterface $element, string $handle): mixed
    {
        if ($element->hasAttribute($handle) || isset($element->$handle)) {
            return $element->$handle;
        }
        return $element->getFieldValue($handle);
    }

    /**
     * Coerce a value into a comparable form for hasChanged().
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        return json_encode($value);
    }
}
