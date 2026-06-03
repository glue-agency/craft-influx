<?php

namespace TDM\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use TDM\Influx\models\Link;

abstract class AbstractElementTarget implements ElementTargetInterface
{
    public function handles(Link $link): bool
    {
        return ltrim($link->elementType, '\\') === ltrim(static::elementType(), '\\');
    }

    public function disable(ElementInterface $element): bool
    {
        $element->enabled = false;
        return Craft::$app->getElements()->saveElement($element, false);
    }

    public function delete(ElementInterface $element): bool
    {
        return Craft::$app->getElements()->deleteElement($element);
    }

    public function deleteForSite(ElementInterface $element, int $siteId): bool
    {
        return Craft::$app->getElements()->deleteElementForSite($element, $siteId);
    }
}
