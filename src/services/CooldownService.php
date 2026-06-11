<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * Per-element manual-sync rate limiter, backed by Craft's cache.
 */
class CooldownService extends Component
{
    protected function key(Link $link, ElementInterface $element): string
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
        $cooldown = Influx::getInstance()->getSettings()->defaultItemCooldown;

        Craft::$app->getCache()->set(
            $this->key($link, $element),
            time() + $cooldown,
            $cooldown,
        );
    }
}
