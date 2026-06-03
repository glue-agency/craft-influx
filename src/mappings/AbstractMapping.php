<?php

namespace TDM\Influx\mappings;

use Cake\Utility\Hash;
use craft\base\ElementInterface;
use TDM\Influx\models\Link;

abstract class AbstractMapping implements MappingInterface
{
    protected function extract(array $item, array $config): mixed
    {
        $node = $config['node'] ?? null;
        if ($node === null) {
            return $config['default'] ?? null;
        }
        return Hash::get($item, $node) ?? ($config['default'] ?? null);
    }

    public function hasChanged(
        ElementInterface $element,
        string $targetFieldHandle,
        array $item,
        array $config,
        Link $link,
    ): bool {
        $current = $this->currentValue($element, $targetFieldHandle);
        $incoming = $this->extract($item, $config);

        return $this->normalize($current) !== $this->normalize($incoming);
    }

    protected function currentValue(ElementInterface $element, string $handle): mixed
    {
        if ($element->hasAttribute($handle) || isset($element->$handle)) {
            return $element->$handle;
        }
        return $element->getFieldValue($handle);
    }

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
