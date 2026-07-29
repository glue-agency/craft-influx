<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\Tests\unit\Support\TranslationScanner;
use GlueAgency\Influx\web\LinkBuilderTranslations;

/**
 * Anti-drift lock between the PHP translation catalogue and the LinkBuilder
 * SPA: the catalogue is what {@see \GlueAgency\Influx\controllers\LinksController::builderScreen()}
 * hands to `$view->registerTranslations()`, so a string the SPA wraps in `$t()`
 * but the catalogue omits is silently untranslatable, and a catalogue entry no
 * component uses is dead weight a translator still has to service.
 *
 * {@see TranslationScanner} reads every `.vue` / `.js` file under
 * `src/web/assets/cp/src/builder` (specs excluded) and collects the literal
 * first argument of every translation call — `$t('…')` in components, and the
 * bare `t('…')` the store imports from `lib/installT.js`. The builder has no
 * dynamic (variable / template-literal) translation calls, which is asserted
 * here too, so the two sides can be pinned as exact sets rather than "the
 * catalogue must at least contain".
 *
 * The shared components the builder mounts are a tree of their own with a
 * catalogue of their own — see
 * {@see \GlueAgency\Influx\Tests\unit\web\CpAppTranslationsTest}, which locks
 * that tree and the log viewer's and debug inspector's the same way.
 */
class LinkBuilderTranslationsTest extends Unit
{
    public function testTheScanFindsTheSpaStrings(): void
    {
        // Guards the assertions below from passing vacuously on a broken regex
        // or a moved source tree.
        $this->assertGreaterThan(100, count(TranslationScanner::strings('builder')));
    }

    public function testNoBuilderComponentTranslatesADynamicValue(): void
    {
        foreach (TranslationScanner::sources('builder') as $path => $source) {
            $this->assertFalse(
                TranslationScanner::hasDynamicCall($source),
                "{$path} translates a non-literal value; the catalogue can't be scanned for it.",
            );
        }
    }

    public function testEverySpaStringIsInTheCatalogue(): void
    {
        $catalogue = LinkBuilderTranslations::strings();

        foreach (TranslationScanner::strings('builder') as $string => $files) {
            $this->assertContains(
                $string,
                $catalogue,
                "Missing from LinkBuilderTranslations (used in " . implode(', ', $files) . "): {$string}",
            );
        }
    }

    public function testTheCatalogueCarriesNothingTheSpaDoesntUse(): void
    {
        $used = array_keys(TranslationScanner::strings('builder'));

        foreach (LinkBuilderTranslations::strings() as $string) {
            $this->assertContains(
                $string,
                $used,
                "No builder component uses this catalogue entry any more: {$string}",
            );
        }
    }

    public function testTheCatalogueListsEachStringOnce(): void
    {
        $catalogue = LinkBuilderTranslations::strings();

        $this->assertSame(array_values(array_unique($catalogue)), $catalogue);
    }
}
