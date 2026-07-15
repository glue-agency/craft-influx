<?php

namespace GlueAgency\Influx\fields;

use Craft;
use craft\elements\User as CraftUserElement;
use craft\fields\BaseRelationField;
use craft\fields\Users as CraftUsersField;

class Users extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return CraftUsersField::class;
    }

    protected function elementType(): string
    {
        return CraftUserElement::class;
    }

    /**
     * Users aren't localized per-site (one global layout, global rows), so
     * scoping a lookup by site would only narrow it incorrectly.
     */
    protected function scopesBySite(): bool
    {
        return false;
    }

    /**
     * Users share a single global field layout in Craft 5 — there's no
     * per-source layout to walk, so we yield it once.
     */
    protected function sourceFieldLayouts(BaseRelationField $field): iterable
    {
        $layout = Craft::$app->getFields()->getLayoutByType(CraftUserElement::class);

        if ($layout) {
            yield $layout;
        }
    }
}
