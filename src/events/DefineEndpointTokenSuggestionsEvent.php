<?php

namespace GlueAgency\Influx\events;

use GlueAgency\Influx\models\Link;
use yii\base\Event;

/**
 * Fired when the link edit screen builds the Resource Endpoint token picker.
 * Listeners append entries to `$suggestions` so their tokens show up in the
 * "Insert token" menu (see the property's shape; the optional `kind` slug
 * drives styling and falls back to `custom`).
 *
 *   Event::on(
 *       EndpointTokensService::class,
 *       EndpointTokensService::EVENT_DEFINE_ENDPOINT_TOKEN_SUGGESTIONS,
 *       function (DefineEndpointTokenSuggestionsEvent $event) {
 *           $event->suggestions[] = [
 *               'kind'  => 'authors',
 *               'label' => 'Authors',
 *               'data'  => [
 *                   ['name' => '{authorEmail}', 'hint' => 'Entry author email'],
 *               ],
 *           ];
 *       }
 *   );
 */
class DefineEndpointTokenSuggestionsEvent extends Event
{
    public Link $link;

    /** @var list<array{kind?: string, label: string, data: list<array{name: string, hint?: string}>}> */
    public array $suggestions = [];
}
