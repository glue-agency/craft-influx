<?php

namespace TDM\Influx\fields;

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
}
