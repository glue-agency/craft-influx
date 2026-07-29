<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\Tests\unit\Support\TranslationScanner;
use GlueAgency\Influx\web\DebugInspectorTranslations;
use GlueAgency\Influx\web\LogViewerTranslations;
use GlueAgency\Influx\web\SharedComponentTranslations;

/**
 * The same anti-drift lock {@see LinkBuilderTranslationsTest} puts on the link
 * builder, for the other three CP source trees: the log viewer, the debug
 * inspector, and the components both of them (and the builder) mount. Each
 * catalogue is what its screen hands to `$view->registerTranslations()` — see
 * {@see \GlueAgency\Influx\controllers\AbstractController::registerAppTranslations()}
 * — so a string an app wraps in `$t()` but its catalogue omits is silently
 * untranslatable, and a catalogue entry no component uses is dead weight a
 * translator still has to service.
 *
 * One catalogue per tree is what makes both directions assertable as EXACT sets:
 * every string the tree translates is listed, and nothing else is. The shared
 * components therefore have a catalogue of their own rather than a copy inside
 * each app's, and every screen registers that one alongside its app's.
 */
class CpAppTranslationsTest extends Unit
{
    /**
     * catalogue class => the CP source tree it serves, plus a floor for the
     * number of strings that tree translates — the floor is what stops the
     * assertions below from passing vacuously on a broken regex or a moved
     * tree.
     *
     * @return array<string, array{class-string, string, int}>
     */
    public static function catalogues(): array
    {
        return [
            'log viewer'        => [LogViewerTranslations::class, 'logs', 25],
            'debug inspector'   => [DebugInspectorTranslations::class, 'debug', 15],
            'shared components' => [SharedComponentTranslations::class, 'components', 10],
        ];
    }

    /**
     * @dataProvider catalogues
     */
    public function testTheScanFindsTheTreesStrings(string $catalogue, string $tree, int $floor): void
    {
        $this->assertGreaterThan($floor, count(TranslationScanner::strings($tree)));
    }

    /**
     * @dataProvider catalogues
     */
    public function testNoComponentTranslatesADynamicValue(string $catalogue, string $tree): void
    {
        foreach (TranslationScanner::sources($tree) as $path => $source) {
            $this->assertFalse(
                TranslationScanner::hasDynamicCall($source),
                "{$tree}/{$path} translates a non-literal value; the catalogue can't be scanned for it.",
            );
        }
    }

    /**
     * @dataProvider catalogues
     */
    public function testEveryTranslatedStringIsInTheCatalogue(string $catalogue, string $tree): void
    {
        $strings = $catalogue::strings();

        foreach (TranslationScanner::strings($tree) as $string => $files) {
            $this->assertContains(
                $string,
                $strings,
                "Missing from {$catalogue} (used in " . implode(', ', $files) . "): {$string}",
            );
        }
    }

    /**
     * @dataProvider catalogues
     */
    public function testTheCatalogueCarriesNothingTheTreeDoesntUse(string $catalogue, string $tree): void
    {
        $used = array_keys(TranslationScanner::strings($tree));

        foreach ($catalogue::strings() as $string) {
            $this->assertContains(
                $string,
                $used,
                "No component under {$tree}/ uses this {$catalogue} entry any more: {$string}",
            );
        }
    }

    /**
     * @dataProvider catalogues
     */
    public function testTheCatalogueListsEachStringOnce(string $catalogue): void
    {
        $strings = $catalogue::strings();

        $this->assertSame(array_values(array_unique($strings)), $strings);
    }
}
