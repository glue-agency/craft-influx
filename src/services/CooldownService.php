<?php

namespace TDM\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\models\Feed;

/**
 * Per-element manual-sync rate limiter, backed by Craft's cache. Stores the
 * unix timestamp of the next allowed sync per (feed, element).
 */
class CooldownService extends Component
{
    private function key(Feed $feed, ElementInterface $element): string
    {
        return "influx:cooldown:{$feed->handle}:{$element->id}";
    }

    public function remaining(Feed $feed, ElementInterface $element): int
    {
        $until = Craft::$app->getCache()->get($this->key($feed, $element));
        if (!$until) {
            return 0;
        }
        $diff = (int)$until - time();
        return max(0, $diff);
    }

    public function mark(Feed $feed, ElementInterface $element): void
    {
        $defaultCooldown = Influx::getInstance()->getSettings()->defaultItemCooldown;
        $cooldown = $feed->effectiveItemCooldown($defaultCooldown);

        Craft::$app->getCache()->set(
            $this->key($feed, $element),
            time() + $cooldown,
            $cooldown,
        );
    }
}
