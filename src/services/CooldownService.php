<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\models\Link;

/**
 * Per-element manual-sync rate limiter, backed by Craft's cache.
 */
class CooldownService extends Component
{
    private function key(Link $link, ElementInterface $element): string
    {
        return "influx:cooldown:{$link->handle}:{$element->id}";
    }

    public function remaining(Link $link, ElementInterface $element): int
    {
        $until = Craft::$app->getCache()->get($this->key($link, $element));
        if (!$until) {
            return 0;
        }
        $diff = (int)$until - time();
        return max(0, $diff);
    }

    public function mark(Link $link, ElementInterface $element): void
    {
        $defaultCooldown = Influx::getInstance()->getSettings()->defaultItemCooldown;
        $cooldown = $link->effectiveItemCooldown($defaultCooldown);

        Craft::$app->getCache()->set(
            $this->key($link, $element),
            time() + $cooldown,
            $cooldown,
        );
    }
}
