<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\web\LinkBuilderTranslations;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Anti-drift lock between the PHP translation catalogue and the LinkBuilder
 * SPA: the catalogue is what {@see \GlueAgency\Influx\controllers\LinksController::builderScreen()}
 * hands to `$view->registerTranslations()`, so a string the SPA wraps in `$t()`
 * but the catalogue omits is silently untranslatable, and a catalogue entry no
 * component uses is dead weight a translator still has to service.
 *
 * The scan reads every `.vue` / `.js` file under `src/web/assets/cp/src/builder`
 * (specs excluded) and collects the literal first argument of every translation
 * call — `$t('…')` in components, and the bare `t('…')` the store imports from
 * `lib/installT.js`. The builder has no dynamic (variable / template-literal)
 * translation calls, which is asserted here too, so the two sides can be pinned
 * as exact sets rather than "the catalogue must at least contain".
 */
class LinkBuilderTranslationsTest extends Unit
{
    /**
     * `$t(` with any prefix (`this.$t(`), or a bare `t(` whose preceding
     * character is neither a word character nor `$`/`.` — that lookbehind is
     * what keeps it off `format(`, `.split(`, `assert(`.
     */
    protected const T_CALL = '/(?:\$t|(?<![\w$.])t)\(\s*([\'"])((?:\\\\.|(?!\1)[^\\\\])*)\1/';

    /**
     * A translation call whose argument isn't a plain quoted literal — the scan
     * can't read those, so their existence would make the exact-set assertions
     * unsound.
     */
    protected const DYNAMIC_T_CALL = '/(?:\$t|(?<![\w$.])t)\(\s*[^\'"\s)]/';

    public function testTheScanFindsTheSpaStrings(): void
    {
        // Guards the assertions below from passing vacuously on a broken regex
        // or a moved source tree.
        $this->assertGreaterThan(100, count($this->scanBuilderStrings()));
    }

    public function testNoBuilderComponentTranslatesADynamicValue(): void
    {
        foreach ($this->builderSources() as $path => $source) {
            $this->assertSame(
                0,
                preg_match(self::DYNAMIC_T_CALL, $source),
                "{$path} translates a non-literal value; the catalogue can't be scanned for it.",
            );
        }
    }

    public function testEverySpaStringIsInTheCatalogue(): void
    {
        $catalogue = LinkBuilderTranslations::strings();

        foreach ($this->scanBuilderStrings() as $string => $files) {
            $this->assertContains(
                $string,
                $catalogue,
                "Missing from LinkBuilderTranslations (used in " . implode(', ', $files) . "): {$string}",
            );
        }
    }

    public function testTheCatalogueCarriesNothingTheSpaDoesntUse(): void
    {
        $used = array_keys($this->scanBuilderStrings());

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

    /**
     * Every translated literal in the builder sources => the files it appears
     * in, for a failure message that points at the component to fix.
     *
     * @return array<string, list<string>>
     */
    protected function scanBuilderStrings(): array
    {
        $found = [];

        foreach ($this->builderSources() as $path => $source) {
            if (! preg_match_all(self::T_CALL, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $string = stripcslashes($match[2]);

                if (! in_array($path, $found[$string] ?? [], true)) {
                    $found[$string][] = $path;
                }
            }
        }

        return $found;
    }

    /**
     * The builder's own sources, keyed by their path relative to the builder
     * root. Specs are excluded: their fixtures aren't shipped UI.
     *
     * @return array<string, string>
     */
    protected function builderSources(): array
    {
        $root = dirname(__DIR__, 3) . '/src/web/assets/cp/src/builder';
        $this->assertDirectoryExists($root);

        $sources = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || ! preg_match('/\.(vue|js)$/', $file->getFilename())) {
                continue;
            }

            if (str_contains($file->getPathname(), '__tests__')) {
                continue;
            }

            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }

        ksort($sources);

        return $sources;
    }
}
