<?php

namespace GlueAgency\Influx\events;

use craft\base\ElementInterface;
use GlueAgency\Influx\models\Link;
use yii\base\Event;

/**
 * Fired once per per-element sync, just before the Resource Endpoint URL is
 * built. Listeners can append, override, or remove tokens that
 * {@see \GlueAgency\Influx\data\EndpointResolver::itemUrl()} will then interpolate
 * into the URL pattern.
 *
 * The default tokens (`{id}`, `{status}`, `{slug}`, `{site.id}`,
 * `{site.handle}`, `{site.locale}`, and any Dropdown/Email/Number/PlainText/
 * RadioButtons custom field by handle) are already in `$tokens` when the
 * event fires.
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
