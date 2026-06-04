<?php

namespace TDM\Influx\events;

use yii\base\Event;

/**
 * Fired once, lazily, when the auth registry is first asked to resolve a
 * link's auth config. Listeners can append, override, or filter
 * {@see \TDM\Influx\auth\AuthStrategyInterface} implementations.
 *
 * Each registered class declares its discriminator via `::type()`. Adding a
 * new strategy with the same `type()` as a built-in effectively replaces it.
 *
 *   Event::on(
 *       AuthService::class,
 *       AuthService::EVENT_REGISTER_AUTH_TYPES,
 *       function (RegisterAuthTypesEvent $event) {
 *           $event->authTypes[] = HmacAuth::class;
 *       }
 *   );
 */
class RegisterAuthTypesEvent extends Event
{
    /** @var class-string<\TDM\Influx\auth\AuthStrategyInterface>[] */
    public array $authTypes = [];
}
