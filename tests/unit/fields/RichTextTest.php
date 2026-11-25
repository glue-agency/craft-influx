<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use GlueAgency\Influx\fields\RichText;

/**
 * Spec for how rich text compares — {@see RichText::comparableHtml()}.
 *
 * The regression class this pins: saving an element in one site re-serializes
 * its other sites' HTML, and that rewrites a reference tag's `||fallback` with
 * the element's current URL (a `?v=` cache-buster with `revAssetUrls`). The
 * stored HTML then differed from what the feed serializes to although nobody
 * edited it, so the next sync of that site saved the entry again. Fallback text
 * must not count; which element a tag points at must.
 *
 * No Craft boot: the comparison form is a pure string function.
 */
class RichTextTest extends Unit
{
    public function testAReferenceTagsFallbackTextDoesNotCount(): void
    {
        $stored = '<p><a href="{asset:13162771:url||https://wisper.be/volumes/wisper/programma.png?v=1791182198}">HIER</a></p>';
        $fromFeed = '<p><a href="{asset:13162771:url||https://wisper.be/volumes/wisper/programma.png}">HIER</a></p>';

        $this->assertSame(RichText::comparableHtml($fromFeed), RichText::comparableHtml($stored));
        $this->assertSame('<p><a href="{asset:13162771:url}">HIER</a></p>', RichText::comparableHtml($stored));
    }

    public function testTheElementATagPointsAtStillCounts(): void
    {
        $this->assertNotSame(
            RichText::comparableHtml('<a href="{asset:12:url||https://example.test/a.png}">a</a>'),
            RichText::comparableHtml('<a href="{asset:13:url||https://example.test/a.png}">a</a>'),
        );
        $this->assertNotSame(
            RichText::comparableHtml('<a href="{entry:5@1:url||https://example.test/nl/a}">a</a>'),
            RichText::comparableHtml('<a href="{entry:5@2:url||https://example.test/nl/a}">a</a>'),
            'another site of the same entry is another target',
        );
    }

    public function testEveryTagFormIsHandled(): void
    {
        $html = '{entry:5@2:url||https://example.test/en/a} {craft\\elements\\Asset:7:url||https://example.test/b.pdf} {asset:9} {asset:9:filename}';

        $this->assertSame(
            '{entry:5@2:url} {craft\\elements\\Asset:7:url} {asset:9} {asset:9:filename}',
            RichText::comparableHtml($html),
        );
    }

    public function testEverythingElseStillCountsAndOnlySurroundingWhitespaceIsTrimmed(): void
    {
        $this->assertSame('<p>Hallo {not a tag} || wereld</p>', RichText::comparableHtml("  <p>Hallo {not a tag} || wereld</p>\n"));
        $this->assertNotSame(RichText::comparableHtml('<p>Hallo  wereld</p>'), RichText::comparableHtml('<p>Hallo wereld</p>'));
        $this->assertNotSame(
            RichText::comparableHtml('<p style="font-size:10px;">x</p>'),
            RichText::comparableHtml('<p style="font-size:12px;">x</p>'),
        );
    }
}
