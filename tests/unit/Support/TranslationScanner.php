<?php

namespace GlueAgency\Influx\Tests\unit\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Reads the literal strings a CP Vue source tree hands to its translation
 * helper, so the PHP catalogue that serves that tree can be pinned against it.
 * Shared by every catalogue's anti-drift test — the exactness those tests claim
 * rests on these two patterns, so there is one copy of them.
 */
class TranslationScanner
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

    /**
     * Every translated literal in one tree => the files it appears in, for a
     * failure message that points at the component to fix.
     *
     * @return array<string, list<string>>
     */
    public static function strings(string $tree): array
    {
        $found = [];

        foreach (self::sources($tree) as $path => $source) {
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
     * Whether a source translates something the scan can't read — a variable, a
     * template literal, an expression.
     */
    public static function hasDynamicCall(string $source): bool
    {
        return preg_match(self::DYNAMIC_T_CALL, $source) === 1;
    }

    /**
     * One CP source tree ('builder', 'logs', 'debug', 'components'), keyed by
     * each file's path relative to that tree's root. Specs are excluded: their
     * fixtures aren't shipped UI.
     *
     * @return array<string, string>
     */
    public static function sources(string $tree): array
    {
        $root = dirname(__DIR__, 3) . '/src/web/assets/cp/src/' . $tree;

        if (! is_dir($root)) {
            throw new RuntimeException("No such CP source tree: {$root}");
        }

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
