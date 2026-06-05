<?php

namespace TDM\Influx\fields;

use Craft;
use craft\elements\User as UserElement;

class Users extends Relation
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Users::class;
    }

    protected function elementType(): string
    {
        return UserElement::class;
    }

    /**
     * Users share a single global field layout in Craft 5 — there's no
     * per-source layout to walk, so we yield it once.
     */
    protected function sourceFieldLayouts(\craft\fields\BaseRelationField $field): iterable
    {
        $layout = Craft::$app->getFields()->getLayoutByType(UserElement::class);
        if ($layout) {
            yield $layout;
        }
    }
}
