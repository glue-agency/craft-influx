<?php

namespace TDM\Influx\fields;

/**
 * Placeholder strategy for Craft's Matrix field. A real implementation is a
 * later iteration — for now this exists purely so the mapping UI can render
 * a `kind: matrix` marker (which the Vue side uses to disable controls that
 * don't make sense yet). Parsing falls through to {@see Field::fetchSimpleValue},
 * same as {@see DefaultField}.
 */
class Matrix extends Field
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Matrix::class;
    }

    public function fieldMeta(\craft\base\FieldInterface $field): array
    {
        return ['kind' => 'matrix'];
    }

    public function parseField(): mixed
    {
        return $this->fetchSimpleValue();
    }
}
