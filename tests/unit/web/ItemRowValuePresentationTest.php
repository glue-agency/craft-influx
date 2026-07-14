<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use DateTime;
use GlueAgency\Influx\web\ItemRowPresenter;

/**
 * Behaviour spec for {@see ItemRowPresenter::describeValue()}, the stringifier
 * feeding the Incoming/Parsed/Current columns of both the debug inspector and
 * the log drill-down. Focused on the editor-friendly value kinds — booleans,
 * option field data, dates — plus the plain scalar/null passthrough.
 *
 * Pure: the presenter touches Craft only inside its methods, so it's
 * constructible here without a boot. The unit suite boots no app (see
 * tests/_bootstrap.php), so the date path deterministically falls back to the
 * fixed format — exactly the "no app" branch we want to lock in.
 */
class ItemRowValuePresentationTest extends Unit
{
    protected ItemRowPresenter $presenter;

    protected function _before(): void
    {
        $this->presenter = new ItemRowPresenter();
    }

    public function testBooleansRenderAsWordsNotCastArtifacts(): void
    {
        // A `(string)` cast would give `'1'` / `''`; the empty string reads as
        // a blank cell the Vue layer can't tell from "no value".
        $this->assertSame('true', $this->presenter->describeValue(true));
        $this->assertSame('false', $this->presenter->describeValue(false));
    }

    public function testSingleOptionRendersLabel(): void
    {
        $option = new SingleOptionFieldData('Te koop', 'for_sale', true);

        // The label (what the editor picks and the dropdown shows) — not the
        // bare `for_sale` its __toString yields.
        $this->assertSame('Te koop', $this->presenter->describeValue($option));
    }

    public function testSingleOptionWithoutLabelDegradesToValue(): void
    {
        // Invalid/unknown stored value: no label, so just the bare value.
        $option = new SingleOptionFieldData(null, 'for_sale', true, false);

        $this->assertSame('for_sale', $this->presenter->describeValue($option));
    }

    public function testMultiOptionsRenderEachOptionJoined(): void
    {
        $value = new MultiOptionsFieldData([
            new OptionData('Te koop', 'for_sale', true),
            new OptionData('Verkocht', 'sold', true),
            new OptionData(null, 'archived', true, false),
        ]);

        // Each contained option rendered like the single case, joined — not the
        // option-object JSON blob the ArrayObject would fall through to.
        $this->assertSame(
            'Te koop, Verkocht, archived',
            $this->presenter->describeValue($value),
        );
    }

    public function testDateFallsBackToFixedFormatWithoutBootedApp(): void
    {
        $date = new DateTime('2024-03-02 14:30:00');

        // No app booted here, so the formatter can't run — the column keeps its
        // pre-existing fixed rendering rather than throwing.
        $this->assertSame('2024-03-02 14:30:00', $this->presenter->describeValue($date));
    }

    public function testPlainScalarsAndNullAreUnchanged(): void
    {
        $this->assertSame('7', $this->presenter->describeValue(7));
        $this->assertSame('hello', $this->presenter->describeValue('hello'));
        $this->assertNull($this->presenter->describeValue(null));
    }
}
