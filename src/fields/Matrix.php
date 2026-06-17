<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Matrix as CraftMatrixField;
use GlueAgency\Influx\helpers\BuilderSchema;

/**
 * Placeholder strategy for Craft's Matrix field. A real implementation is a
 * later iteration — for now this exists purely so the mapping editor shows a
 * "not yet supported" note (declared in {@see defineExtrasSchema()}). Parsing
 * falls through to {@see DefaultField}'s plain resolution, which is why this
 * extends it rather than re-implementing the same `parse()`.
 */
class Matrix extends DefaultField
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
}
