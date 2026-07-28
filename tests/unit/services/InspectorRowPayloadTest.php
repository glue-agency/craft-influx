<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\services\InspectorService;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\web\ItemRowPresenter;

/**
 * Contract test for the inspector row — the shape both drill-downs speak and
 * DebugItemDetail.vue consumes. PHP is the authority in two halves:
 * {@see InspectorService::itemRow()} declares the item envelope and
 * {@see ItemRowPresenter::presentMappingResults()} its `mappings` rows. The
 * committed fixture is the contract artifact; the SPA asserts the component's
 * own assumptions against the same file
 * (`src/web/assets/cp/src/components/__tests__/inspector-row.contract.test.js`).
 *
 * `parsedHtml` is null on every fixture row on purpose: the key is always
 * present, and only the log drill-down (`withParsedHtml: true`, which needs a
 * booted Craft to render chips / a lightswitch) ever fills it. The JS side
 * covers the filled variant.
 *
 * If this test fails after a deliberate shape change: update the fixture and the
 * JS contract test together.
 */
class InspectorRowPayloadTest extends Unit
{
    public function testItemEnvelopeCarriesExactlyTheFixtureKeys(): void
    {
        $this->assertSame(
            array_keys($this->fixture()),
            array_keys(InspectorService::itemRow()),
            'The inspector row envelope drifted from the committed wire-contract fixture.',
        );
    }

    public function testUnresolvedItemRowDefaultsToASkip(): void
    {
        $row = InspectorService::itemRow(['raw' => ['id' => '42']]);

        $this->assertSame('would-skip', $row['action']);
        $this->assertSame(['id' => '42'], $row['raw']);
        $this->assertNull($row['element']);
        $this->assertSame([], $row['mappings']);
        $this->assertFalse($row['isNew']);
    }

    public function testMappingRowsMatchTheCommittedFixture(): void
    {
        $rows = (new ItemRowPresenter())->presentMappingResults(
            $this->results(),
            $this->createMock(ElementInterface::class),
            ['importId' => 'Import ID', 'title' => 'Title', 'building_type' => 'Building type'],
        );

        $this->assertEquals(
            $this->fixture()['mappings'],
            $rows,
            'ItemRowPresenter::presentMappingResults() drifted from the committed wire-contract fixture.',
        );

        // Key order is part of the contract — the Vue rows are read by key, but
        // a reordered row means someone rewrote the emitter.
        $this->assertSame(array_keys($this->fixture()['mappings'][0]), array_keys($rows[0]));
    }

    public function testEveryMappingRowCarriesTheFullKeySet(): void
    {
        foreach ($this->fixture()['mappings'] as $row) {
            $this->assertSame(array_keys($this->fixture()['mappings'][0]), array_keys($row));
            $this->assertArrayHasKey('parsedHtml', $row, 'The key is always present; only the log context fills it.');
        }
    }

    /**
     * The mapping outcomes the row shape has to carry: the link's match field
     * (unchanged), a native attribute that changed, and a custom field the feed
     * didn't address whose strategy errored.
     *
     * @return list<MappingResult>
     */
    protected function results(): array
    {
        return [
            new MappingResult(
                handle: 'importId',
                node: 'id',
                default: null,
                native: false,
                rawValue: '42',
                parsedValue: '42',
                currentValue: '42',
                changed: false,
            ),
            new MappingResult(
                handle: 'title',
                node: 'title',
                default: null,
                native: true,
                rawValue: 'Werfkelder',
                parsedValue: 'Werfkelder',
                currentValue: 'Kelder',
                changed: true,
            ),
            new MappingResult(
                handle: 'building_type',
                node: 'building_type.id',
                default: '7',
                native: false,
                rawValue: null,
                parsedValue: null,
                currentValue: 'Kelder (#43)',
                changed: false,
                unaddressed: true,
                error: "Relation lookup failed: no element matches '7'.",
            ),
        ];
    }

    protected function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/cp/tests/fixtures/inspector-row.json';

        return json_decode(file_get_contents($path), true);
    }
}
