<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use GlueAgency\Influx\events\RegisterEndpointTokensEvent;
use GlueAgency\Influx\events\RegisterEndpointTokenSuggestionsEvent;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\support\EntryTypeResolver;

/**
 * Builds the `{token}` substitution map for a link's Resource Endpoint URL
 * template (the per-element "Sync from remote" path), plus the matching
 * picker suggestions for the link edit screen.
 *
 * Extracted from {@see SynchronizationService} — this is CP/URL-template
 * machinery, not sync-pipeline logic.
 */
class EndpointTokensService extends Component
{
    /**
     * Fires while building the runtime token map for one element, so plugins
     * can contribute extra tokens. Receives a {@see RegisterEndpointTokensEvent}.
     */
    public const EVENT_REGISTER_ENDPOINT_TOKENS = 'registerEndpointTokens';

    /**
     * Fires while building the edit-screen "Insert token" picker payload.
     * Receives a {@see RegisterEndpointTokenSuggestionsEvent}.
     */
    public const EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS = 'registerEndpointTokenSuggestions';

    /**
     * Custom field classes whose value is a single printable scalar and
     * therefore safe to expose as a Resource Endpoint URL token. Shared by
     * {@see self::tokensForElement()} (runtime value) and
     * {@see self::suggestions()} (edit-screen picker).
     */
    protected const TOKEN_FIELD_TYPES = [
        Dropdown::class,
        Email::class,
        Number::class,
        PlainText::class,
        RadioButtons::class,
    ];

    /**
     * Build the token map used by the link's Resource Endpoint URL template.
     *
     * Exposes a small, predictable set:
     *   - Native attributes: {id}, {status}, {slug}
     *   - Current site: {site.id}, {site.handle}, {site.locale}
     *   - Custom fields, limited to the field types whose value is a single
     *     printable scalar: Dropdown, Email, Number, PlainText, RadioButtons.
     *
     * Anything else (relations, assets, matrices, dates, lightswitches, ...)
     * is intentionally not exposed — they don't have an obvious URL form.
     * Plugins can contribute more tokens via {@see self::EVENT_REGISTER_ENDPOINT_TOKENS}.
     *
     * @return array<string, string>
     */
    public function tokensForElement(Link $link, ElementInterface $element, ?string $siteHandle): array
    {
        $tokens = [];

        foreach (['id', 'status', 'slug'] as $attr) {
            $v = $element->$attr ?? null;

            if (is_scalar($v) && $v !== '') {
                $tokens[$attr] = (string) $v;
            }
        }

        $site = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : (method_exists($element, 'getSite') ? $element->getSite() : null);

        if ($site) {
            $tokens['site.id'] = (string) $site->id;
            $tokens['site.handle'] = $site->handle;
            $tokens['site.locale'] = $site->language;
        }

        if (method_exists($element, 'getFieldLayout')) {
            $layout = $element->getFieldLayout();

            if ($layout) {
                foreach ($layout->getCustomFields() as $field) {
                    if (! in_array($field::class, self::TOKEN_FIELD_TYPES, true)) {
                        continue;
                    }
                    $handle = $field->handle;

                    if (isset($tokens[$handle])) {
                        continue;
                    }
                    $v = $element->getFieldValue($handle);

                    if ($v !== null && (string) $v !== '') {
                        $tokens[$handle] = (string) $v;
                    }
                }
            }
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_ENDPOINT_TOKENS)) {
            $event = new RegisterEndpointTokensEvent([
                'link'       => $link,
                'element'    => $element,
                'siteHandle' => $siteHandle,
                'tokens'     => $tokens,
            ]);
            $this->trigger(self::EVENT_REGISTER_ENDPOINT_TOKENS, $event);
            $tokens = $event->tokens;
        }

        return $tokens;
    }

    /**
     * Token suggestions surfaced by the link edit screen's "Insert token"
     * picker on the Resource Endpoint input. Mirrors {@see self::tokensForElement()}
     * so what the picker advertises matches what's actually substituted at
     * sync-time. Plugins can append more via
     * {@see self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS}.
     *
     * @return list<array{label: string, data: list<array{name: string, hint?: string}>}>
     */
    public function suggestions(Link $link): array
    {
        $suggestions = [
            [
                'kind'  => 'element',
                'label' => Craft::t('influx', 'Element'),
                'data'  => [
                    ['name' => '{id}',     'hint' => Craft::t('influx', 'Element ID')],
                    ['name' => '{status}', 'hint' => Craft::t('influx', 'Status')],
                    ['name' => '{slug}',   'hint' => Craft::t('influx', 'Slug')],
                ],
            ],
            [
                'kind'  => 'site',
                'label' => Craft::t('influx', 'Site'),
                'data'  => [
                    ['name' => '{site.id}',     'hint' => Craft::t('influx', 'Site ID')],
                    ['name' => '{site.handle}', 'hint' => Craft::t('influx', 'Site handle')],
                    ['name' => '{site.locale}', 'hint' => Craft::t('influx', 'Site locale')],
                ],
            ],
        ];

        $fieldItems = [];

        foreach ($this->customFieldsForLink($link) as $field) {
            if (! in_array($field::class, self::TOKEN_FIELD_TYPES, true)) {
                continue;
            }
            $fieldItems[] = [
                'name' => '{' . $field->handle . '}',
                'hint' => $field->name,
            ];
        }

        if ($fieldItems) {
            $suggestions[] = [
                'kind'  => 'fields',
                'label' => Craft::t('influx', 'Fields'),
                'data'  => $fieldItems,
            ];
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS)) {
            $event = new RegisterEndpointTokenSuggestionsEvent([
                'link'        => $link,
                'suggestions' => $suggestions,
            ]);
            $this->trigger(self::EVENT_REGISTER_ENDPOINT_TOKEN_SUGGESTIONS, $event);
            $suggestions = $event->suggestions;
        }

        return $suggestions;
    }

    /**
     * Custom fields on the entry type that the configured link points at,
     * or an empty list when the link has no section/type yet. Used by the
     * token picker; runtime token-building reads the live element's layout.
     *
     * @return list<\craft\base\FieldInterface>
     */
    protected function customFieldsForLink(Link $link): array
    {
        $resolved = (new EntryTypeResolver())->tryResolve($link);

        if (! $resolved) {
            return [];
        }

        [, $entryType] = $resolved;
        $layout = $entryType->getFieldLayout();

        return $layout ? $layout->getCustomFields() : [];
    }
}
