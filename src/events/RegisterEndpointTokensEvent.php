<?php

namespace GlueAgency\Influx\events;

use craft\base\ElementInterface;
use GlueAgency\Influx\models\Link;
use yii\base\Event;

/**
 * Fired once per per-element sync, just before the Resource Endpoint URL is
 * built. Listeners can append, override, or remove the tokens that
 * {@see \GlueAgency\Influx\data\EndpointResolver::itemUrl()} interpolates into
 * the URL pattern (the default tokens are already in `$tokens`).
 *
 *   Event::on(
 *       EndpointTokensService::class,
 *       EndpointTokensService::EVENT_REGISTER_ENDPOINT_TOKENS,
 *       function (RegisterEndpointTokensEvent $event) {
 *           $event->tokens['authorEmail'] = $event->element->getAuthor()?->email ?? '';
 *       }
 *   );
 */
class RegisterEndpointTokensEvent extends Event
{
    public Link $link;

    public ElementInterface $element;

    public ?string $siteHandle = null;

    /** @var array<string, string> */
    public array $tokens = [];
}
