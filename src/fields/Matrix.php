<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Matrix as CraftMatrixField;
use GlueAgency\Influx\helpers\BuilderSchema;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Placeholder strategy for Craft's Matrix field. A real implementation is a
 * later iteration — for now this exists purely so the mapping editor shows a
 * "not yet supported" note (declared in {@see defineExtrasSchema()}). Parsing
 * falls through to the mapping's plain resolution, same as {@see DefaultField}.
 */
class Matrix extends Field
{
    public static function craftFieldClass(): ?string
    {
        return CraftMatrixField::class;
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
