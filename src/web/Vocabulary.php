<?php

namespace GlueAgency\Influx\web;

use Craft;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\enums\ItemAction;

/**
 * The enum-derived UI vocabulary the Vue apps need — action colours and the log
 * viewer's counter definitions — as one JSON-ready payload.
 *
 * Rides along in each app's bootstrap config (the `data-influx-log` /
 * `data-influx-debug` attributes the host templates render), the same channel
 * every other server value reaches them through, so the JS never re-encodes
 * what {@see ItemAction} and {@see ChildAction} already know.
 * `src/web/assets/cp/src/lib/vocabulary.js` consumes it; the generated default
 * it boots from is a copy of this payload, pinned by
 * {@see \GlueAgency\Influx\Tests\unit\web\VocabularyTest}.
 */
class Vocabulary
{
    public static function payload(): array
    {
        return [
            'actionColors' => self::actionColors(),
            'counters'     => self::counters(),
        ];
    }

    /**
     * Every action string the apps can render → its badge colour: each
     * {@see ItemAction} and {@see ChildAction} value plus its `dryRunLabel()`,
     * which shares the committed action's colour. UNCHANGED and ERROR label
     * their dry run exactly as their commit, so those collapse onto a single
     * key, as does every key the two enums have in common — they agree on the
     * colour by construction, so the merge order only decides where a shared key
     * sits in the map.
     *
     * @return array<string, string>
     */
    public static function actionColors(): array
    {
        $colors = [];

        foreach (ItemAction::cases() as $case) {
            $colors[$case->value] = $case->color();
            $colors[$case->dryRunLabel()] = $case->color();
        }

        foreach (ChildAction::cases() as $case) {
            $colors[$case->value] = $case->color();
            $colors[$case->dryRunLabel()] = $case->color();
        }

        return $colors;
    }

    /**
     * The log viewer's counter row in display order: the run-wide `itemsSeen`
     * total first (no action, so clicking it clears the filter), then one entry
     * per counted action ({@see ItemAction::countedCases()}).
     *
     * A counter's label IS its action value — 'created', 'disabled', … — so the
     * noun is never spelled out a second time; it's translated here because
     * every other UI string the apps receive is translated server-side. `tone`
     * tints a non-zero value and derives from the badge colour, so a counter is
     * never coloured differently from the rows it filters to.
     *
     * @return list<array{key: string, action: string|null, label: string, tone: string|null}>
     */
    public static function counters(): array
    {
        $counters = [[
            'key'    => 'itemsSeen',
            'action' => null,
            'label'  => Craft::t('influx', 'seen'),
            'tone'   => null,
        ]];

        foreach (ItemAction::countedCases() as $case) {
            $counters[] = [
                'key'    => (string) $case->counterAttribute(),
                'action' => $case->value,
                'label'  => Craft::t('influx', $case->value),
                'tone'   => self::tone($case->color()),
            ];
        }

        return $counters;
    }

    /**
     * Badge colour → counter tone: a wrote/destructive counter is tinted, a
     * neutral one isn't.
     */
    protected static function tone(string $color): ?string
    {
        return match ($color) {
            'live'    => 'good',
            'expired' => 'bad',
            default   => null,
        };
    }
}
