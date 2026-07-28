<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\web\Vocabulary;

/**
 * The contract between the PHP enums and the Vue apps: every action string the
 * apps can render must arrive with a colour, and the log viewer's counters must
 * be exactly the counted actions.
 *
 * Also pins `src/web/assets/cp/src/lib/vocabulary.generated.json` — the copy the
 * JS boots from before a payload arrives — against this payload, so it can't
 * silently go stale. Regenerate it with:
 *
 *   php -r 'require "vendor/autoload.php"; require "vendor/yiisoft/yii2/Yii.php";
 *   require "vendor/craftcms/cms/src/Craft.php";
 *   file_put_contents("src/web/assets/cp/src/lib/vocabulary.generated.json",
 *   json_encode(GlueAgency\Influx\web\Vocabulary::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);'
 */
class VocabularyTest extends Unit
{
    public function testEveryActionAndDryRunLabelHasAColour(): void
    {
        $colors = Vocabulary::actionColors();

        foreach (ItemAction::cases() as $case) {
            $this->assertArrayHasKey($case->value, $colors, "Missing colour for '{$case->value}'.");
            $this->assertSame($case->color(), $colors[$case->value]);

            $dryRun = $case->dryRunLabel();
            $this->assertArrayHasKey($dryRun, $colors, "Missing colour for dry-run label '{$dryRun}'.");
            $this->assertSame($case->color(), $colors[$dryRun]);
        }
    }

    public function testColourMapCarriesNothingBeyondTheVocabulary(): void
    {
        $known = [];

        foreach (ItemAction::cases() as $case) {
            $known[$case->value] = true;
            $known[$case->dryRunLabel()] = true;
        }

        $this->assertSame(array_keys($known), array_keys(Vocabulary::actionColors()));
    }

    public function testSweepDryRunLabelsAreCovered(): void
    {
        // The four latent sweep labels: the debug inspector can only surface
        // them once a dry run reports a missing-element policy, so they'd be the
        // easiest to forget in a hand-maintained JS map.
        $colors = Vocabulary::actionColors();

        foreach (['would-disable', 'would-disable-for-site', 'would-delete', 'would-delete-for-site'] as $label) {
            $this->assertSame('expired', $colors[$label] ?? null);
        }
    }

    public function testCountersLeadWithSeenThenTheCountedActions(): void
    {
        $counters = Vocabulary::counters();

        $this->assertSame(['key' => 'itemsSeen', 'action' => null, 'label' => 'seen', 'tone' => null], $counters[0]);

        $rest = array_slice($counters, 1);
        $this->assertCount(count(ItemAction::countedCases()), $rest);

        foreach (ItemAction::countedCases() as $i => $case) {
            $this->assertSame($case->counterAttribute(), $rest[$i]['key']);
            $this->assertSame($case->value, $rest[$i]['action']);
            $this->assertSame($case->value, $rest[$i]['label']);
        }
    }

    public function testCounterTonesFollowTheBadgeColour(): void
    {
        $tones = [];

        foreach (Vocabulary::counters() as $counter) {
            $tones[(string) $counter['action']] = $counter['tone'];
        }

        $this->assertSame('good', $tones['created']);
        $this->assertSame('good', $tones['updated']);
        $this->assertNull($tones['unchanged']);
        $this->assertNull($tones['skipped']);
        $this->assertSame('bad', $tones['disabled']);
        $this->assertSame('bad', $tones['deleted']);
    }

    public function testGeneratedJsDefaultMatchesThePayload(): void
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/cp/src/lib/vocabulary.generated.json';

        $this->assertFileExists($path);
        $this->assertSame(
            Vocabulary::payload(),
            json_decode((string) file_get_contents($path), true),
            'The generated JS default drifted from the enums — regenerate it (see this class’s docblock).',
        );
    }
}
