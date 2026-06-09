<?php

namespace TDM\Influx\events;

use TDM\Influx\models\Link;
use yii\base\Event;

/**
 * Fired when the link edit screen builds the Resource Endpoint token picker.
 * Listeners append entries to `$suggestions` so their tokens show up in the
 * "Append token" menu next to the input.
 *
 * Each entry is a `{label, data}` group where `data` is a list of
 * `{name, hint}` items. `name` is the token literal that gets inserted
 * (including the curly braces, e.g. `{authorEmail}`); `hint` is the
 * description shown to the right of it.
 *
 * The optional `kind` field is a stable slug used to drive styling — built-in
 * groups use `element`, `site`, and `fields`. Plugin groups can omit it (the
 * Twig template falls back to `custom`) or supply their own slug.
 *
 *   Event::on(
 *       SynchronizationService::class,
 *       SynchronizationService::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS,
 *       function (RegisterEndpointTokenSuggestionsEvent $event) {
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
class RegisterEndpointTokenSuggestionsEvent extends Event
{
    public Link $link;

    /** @var list<array{kind?: string, label: string, data: list<array{name: string, hint?: string}>}> */
    public array $suggestions = [];
}
