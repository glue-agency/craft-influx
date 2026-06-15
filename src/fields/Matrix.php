<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Matrix as CraftMatrixField;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Placeholder strategy for Craft's Matrix field. A real implementation is a
 * later iteration — for now this exists purely so the mapping UI can render
 * a `kind: matrix` marker (which the Vue side uses to disable controls that
 * don't make sense yet). Parsing falls through to the mapping's plain
 * resolution, same as {@see DefaultField}.
 */
class Matrix extends Field
{
    public static function craftFieldClass(): ?string
    {
        return CraftMatrixField::class;
    }

    public function fieldMeta(CraftFieldInterface $field): array
    {
        return [
            'kind'   => 'matrix',
            'labels' => self::extrasLabels() + self::commonExtrasLabels(),
        ];
    }

    /**
     * UI strings rendered inside the matrix extras block (currently just
     * a placeholder until block-shaped mapping ships).
     *
     * @return array<string, string>
     */
    public static function extrasLabels(): array
    {
        return [
            'placeholder' => Craft::t('influx', 'Matrix block mapping is not yet supported. Map remote sub-arrays here in a future update.'),
        ];
    }

    public function defineExtrasSchema(CraftFieldInterface $field): array
    {
        return [
            BuilderSchema::note(
                Craft::t('influx', 'Matrix block mapping is not yet supported. Map remote sub-arrays here in a future update.'),
            ),
        ];
    }

    public function parse(FieldContext $context): mixed
    {
        return $context->mapping->resolve($context->item);
    }
}
