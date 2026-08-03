<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field as CraftField;
use craft\models\FieldLayout;
use GlueAgency\Influx\services\InspectorService;
use GlueAgency\Influx\sync\item\ChildResult;
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
 * The fixture's children deliberately span BOTH drill-downs' vocabularies — the
 * Matrix blocks carry the dry run's `would-*` labels, the related entry the
 * committed `updated` a real run reports ({@see ChildResult::$action}) — because
 * the one shape serves both, and a single fixture has to pin every identity
 * variant: a child standing for a saved element (the unchanged block and the
 * relation child, each with a presented element), one the sync would only add,
 * which carries neither an element nor a title, and a Table ROW, which can never
 * carry either and whose cells are named from the child's own label map.
 *
 * If this test fails after a deliberate shape change: update the fixture and the
 * JS contract test together.
 */
class InspectorRowPayloadTest extends Unit
{
    /**
     * The mapping-row keys, in emission order — mirrored in the JS contract test.
     * Order is part of the contract: the Vue rows are read by key, but a
     * reordered row means someone rewrote the emitter.
     */
    protected const MAPPING_KEYS = [
        'handle', 'label', 'node', 'default', 'native', 'rawValue', 'parsedValue',
        'parsedHtml', 'currentValue', 'changed', 'unaddressed', 'usedDefault',
        'managedByTarget', 'error', 'children', 'childrenType',
    ];

    /** The per-child keys a row's `children` entries carry. */
    protected const CHILD_KEYS = ['title', 'blockType', 'element', 'action', 'mappings'];

    /** {@see ItemRowPresenter::presentElement()}'s shape, on the item and on a relation child alike. */
    protected const ELEMENT_KEYS = ['id', 'title', 'cpEditUrl', 'siteId', 'chipHtml'];

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
        $rows = $this->presenter()->presentMappingResults(
            $this->results(),
            $this->createMock(ElementInterface::class),
            [
                'importId'         => 'Import ID',
                'title'            => 'Title',
                'building_type'    => 'Building type',
                'content_blocks'   => 'Content blocks',
                'related_projects' => 'Related projects',
                'specs'            => 'Specs',
            ],
        );

        $this->assertEquals(
            $this->fixture()['mappings'],
            $rows,
            'ItemRowPresenter::presentMappingResults() drifted from the committed wire-contract fixture.',
        );

        $this->assertSame(self::MAPPING_KEYS, array_keys($rows[0]));
    }

    public function testEveryMappingRowCarriesTheFullKeySet(): void
    {
        foreach ($this->fixture()['mappings'] as $row) {
            $this->assertSame(self::MAPPING_KEYS, array_keys($row));
            $this->assertArrayHasKey('parsedHtml', $row, 'The key is always present; only the log context fills it.');
        }
    }

    /**
     * The nested half of the contract, walked to whatever depth the fixture goes:
     * every child carries exactly {@see CHILD_KEYS}, and its own rows are rows in
     * the same full shape — which is what makes the drill-down renderable by one
     * recursive component.
     */
    public function testEveryChildCarriesTheChildKeySetAndFullMappingRows(): void
    {
        $children = $this->walkChildren($this->fixture()['mappings']);

        $this->assertNotEmpty($children, 'The fixture must pin at least one children-bearing row.');

        foreach ($children as $child) {
            $this->assertSame(self::CHILD_KEYS, array_keys($child));

            foreach ($child['mappings'] as $row) {
                $this->assertSame(self::MAPPING_KEYS, array_keys($row));
            }
        }
    }

    /**
     * `childrenType` is the noun beside the children, never on its own: a row that
     * nests nothing carries null for both.
     */
    public function testChildrenTypeTravelsWithChildren(): void
    {
        foreach ($this->fixture()['mappings'] as $row) {
            $this->assertSame($row['children'] === null, $row['childrenType'] === null);
        }
    }

    public function testSavedChildrenCarryAnElementAndAddedOnesDont(): void
    {
        $blocks = $this->childrenOf('content_blocks');
        $entries = $this->childrenOf('related_projects');

        // A block that already exists is a saved element — the drill-down heads
        // it with its chip and links straight to it.
        $this->assertSame(self::ELEMENT_KEYS, array_keys($blocks[0]['element']));
        $this->assertSame(512, $blocks[0]['element']['id']);
        $this->assertSame('text', $blocks[0]['blockType']);

        // A block the sync would add doesn't exist yet: no identity, and no title
        // either — the drill-down labels it by its ordinal.
        $this->assertNull($blocks[1]['element']);
        $this->assertNull($blocks[1]['title']);

        $this->assertNull($entries[0]['blockType']);
        $this->assertSame(self::ELEMENT_KEYS, array_keys($entries[0]['element']));
        $this->assertSame(412, $entries[0]['element']['id']);
    }

    /**
     * Child rows are labelled from the CHILD's layout, not the target's mappable
     * fields — plus the native sub-rows, which are attributes and so live on no
     * layout at all.
     */
    public function testChildRowsAreLabelledFromTheirOwnLayout(): void
    {
        $rows = $this->childrenOf('content_blocks')[0]['mappings'];

        $this->assertSame(['title' => 'Title', 'body' => 'Body'], array_column($rows, 'label', 'handle'));
        $this->assertSame('Summary', $this->childrenOf('related_projects')[0]['mappings'][0]['label']);
    }

    /**
     * A Table row's cells are COLUMNS, keyed by column id — no field layout knows
     * them, so the child supplies the labels itself and they have to win.
     */
    public function testATableRowsCellsAreLabelledFromTheChildsOwnMap(): void
    {
        $children = $this->childrenOf('specs');

        $this->assertSame(['unchanged', 'would-remove'], array_column($children, 'action'));

        foreach ($children as $child) {
            // Neither identity nor title: a table row is no element, and the pane
            // labels it by its ordinal.
            $this->assertNull($child['element']);
            $this->assertNull($child['title']);
            $this->assertNull($child['blockType']);

            $this->assertSame(
                ['col1' => 'Kenmerk', 'col2' => 'Waarde'],
                array_column($child['mappings'], 'label', 'handle'),
            );
        }
    }

    /**
     * Every child of every row in the given rows, recursively.
     *
     * @param list<array> $rows
     * @return list<array>
     */
    protected function walkChildren(array $rows): array
    {
        $children = [];

        foreach ($rows as $row) {
            foreach ($row['children'] ?? [] as $child) {
                $children[] = $child;
                $children = [...$children, ...$this->walkChildren($child['mappings'])];
            }
        }

        return $children;
    }

    /**
     * @return list<array>
     */
    protected function childrenOf(string $handle): array
    {
        foreach ($this->fixture()['mappings'] as $row) {
            if ($row['handle'] === $handle) {
                return $row['children'];
            }
        }

        return [];
    }

    /**
     * The presenter with its element-chip seam stubbed: a real Craft chip needs a
     * booted app and a CP request, and the unit suite has neither. The stub still
     * derives the markup from the element, so the fixture's `chipHtml` stays a
     * rendering of the element beside it rather than an unrelated string.
     */
    protected function presenter(): ItemRowPresenter
    {
        return new class() extends ItemRowPresenter {
            public function elementChip(ElementInterface $element): string
            {
                return '<a class="chip small" href="' . $element->getCpEditUrl() . '">' . $element->title . '</a>';
            }
        };
    }

    /**
     * The mapping outcomes the row shape has to carry: the link's match field
     * (unchanged), a native attribute that changed, a custom field the feed
     * didn't address whose strategy errored, a Matrix field nesting blocks, and a
     * relation whose own id-set didn't change while the element it relates did.
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
            // Node-less: a Matrix row's value derives from its per-block-type
            // sub-mappings, so the raw side stays empty and the parsed side is the
            // serialized block payload.
            new MappingResult(
                handle: 'content_blocks',
                node: null,
                default: null,
                native: false,
                rawValue: null,
                parsedValue: [
                    'new1' => ['type' => 'text', 'enabled' => true, 'title' => 'Werfkelder', 'fields' => ['body' => '14de-eeuwse kelder']],
                    'new2' => ['type' => 'image', 'enabled' => true, 'fields' => ['caption' => 'Voorzijde']],
                ],
                currentValue: '(#512)',
                changed: true,
                children: $this->blockChildren(),
                childrenType: 'blocks',
            ),
            new MappingResult(
                handle: 'related_projects',
                node: 'projects.id',
                default: null,
                native: false,
                rawValue: '412',
                parsedValue: [412],
                currentValue: 'Werfkelder Zuid (#412)',
                changed: false,
                children: [$this->relationChild()],
                childrenType: 'entries',
            ),
            // Node-less too: a Table row's value is its per-COLUMN sub-mappings,
            // and its children are the table's ROWS — the one child flavour that
            // is no element at all.
            new MappingResult(
                handle: 'specs',
                node: null,
                default: null,
                native: false,
                rawValue: null,
                parsedValue: [
                    ['col1' => 'Breedte', 'col2' => '120'],
                ],
                currentValue: [
                    ['col1' => 'Breedte', 'col2' => '120'],
                    ['col1' => 'Diepte', 'col2' => '60'],
                ],
                changed: true,
                children: $this->rowChildren(),
                childrenType: 'rows',
            ),
        ];
    }

    /**
     * The two block children a Matrix drill-down shows: one already matching the
     * feed — a saved block, so it travels with its own title AND its element
     * identity — and one the dry run would add, which has neither yet.
     *
     * @return list<ChildResult>
     */
    protected function blockChildren(): array
    {
        $block = $this->fakeElement(71, ['body' => 'Body'], 512, 'Werfkelder', 'https://example.test/admin/edit/512');

        return [
            new ChildResult(
                title: 'Werfkelder',
                blockType: 'text',
                element: $block,
                labelElement: $block,
                action: 'unchanged',
                mappingResults: [
                    new MappingResult(
                        handle: 'title',
                        node: 'blocks.heading',
                        default: null,
                        native: true,
                        rawValue: 'Werfkelder',
                        parsedValue: 'Werfkelder',
                        currentValue: 'Werfkelder',
                        changed: false,
                    ),
                    new MappingResult(
                        handle: 'body',
                        node: 'blocks.body',
                        default: null,
                        native: false,
                        rawValue: '14de-eeuwse kelder',
                        parsedValue: '14de-eeuwse kelder',
                        currentValue: '14de-eeuwse kelder',
                        changed: false,
                    ),
                ],
            ),
            // No title anywhere — neither mapped by the feed nor borrowed from a
            // partner block — so the drill-down labels it by its ordinal instead.
            new ChildResult(
                blockType: 'image',
                labelElement: $this->fakeLabelElement(72, ['caption' => 'Caption']),
                action: 'would-add',
                mappingResults: [
                    new MappingResult(
                        handle: 'caption',
                        node: 'blocks.caption',
                        default: null,
                        native: false,
                        rawValue: 'Voorzijde',
                        parsedValue: 'Voorzijde',
                        currentValue: null,
                        changed: true,
                    ),
                ],
            ),
        ];
    }

    /**
     * The two row children a Table drill-down shows: one the feed leaves exactly
     * as it is, and one the full replace drops. Neither is an element — a table
     * row has no identity and no title of its own, so the pane labels both by
     * their ordinal — and their cells are named from the child's OWN label map
     * ({@see ChildResult::$labels}), there being no layout anywhere that knows
     * `col1` is "Kenmerk".
     *
     * @return list<ChildResult>
     */
    protected function rowChildren(): array
    {
        $labels = ['col1' => 'Kenmerk', 'col2' => 'Waarde'];

        return [
            new ChildResult(
                labels: $labels,
                action: 'unchanged',
                mappingResults: [
                    new MappingResult(
                        handle: 'col1',
                        node: 'specs.label',
                        default: null,
                        native: false,
                        rawValue: 'Breedte',
                        parsedValue: 'Breedte',
                        currentValue: 'Breedte',
                        changed: false,
                    ),
                    new MappingResult(
                        handle: 'col2',
                        node: 'specs.value',
                        default: null,
                        native: false,
                        rawValue: '120',
                        parsedValue: '120',
                        currentValue: '120',
                        changed: false,
                    ),
                ],
            ),
            new ChildResult(
                labels: $labels,
                action: 'would-remove',
                mappingResults: [
                    new MappingResult(
                        handle: 'col1',
                        node: 'specs.label',
                        default: null,
                        native: false,
                        rawValue: null,
                        currentValue: 'Diepte',
                        changed: null,
                    ),
                    new MappingResult(
                        handle: 'col2',
                        node: 'specs.value',
                        default: null,
                        native: false,
                        rawValue: null,
                        currentValue: '60',
                        changed: null,
                    ),
                ],
            ),
        ];
    }

    /**
     * The related element the feed also wrote through sub-mappings: it IS the
     * child's identity and its label source at once
     * ({@see \GlueAgency\Influx\fields\RelationalField::reportChild()}).
     */
    protected function relationChild(): ChildResult
    {
        $element = $this->fakeElement(
            73,
            ['summary' => 'Summary'],
            412,
            'Werfkelder Zuid',
            'https://example.test/admin/entries/projects/412',
        );

        return new ChildResult(
            title: 'Werfkelder Zuid',
            element: $element,
            labelElement: $element,
            action: 'updated',
            mappingResults: [
                new MappingResult(
                    handle: 'summary',
                    node: 'projects.summary',
                    default: null,
                    native: false,
                    rawValue: 'Restauratie',
                    parsedValue: 'Restauratie',
                    currentValue: 'Renovatie',
                    changed: true,
                ),
            ],
        );
    }

    /**
     * A saved element a child stands for: its own field layout (the label source
     * for the child's rows) plus the identity {@see ItemRowPresenter::presentElement()}
     * shapes. Serves both flavours that carry one — the current Matrix block an
     * unchanged child stands for, and the related element a relation child wrote.
     *
     * @param array<string, string> $fields handle => field name
     */
    protected function fakeElement(int $layoutId, array $fields, int $id, string $title, string $cpEditUrl): Element
    {
        $element = $this->createMock(Element::class);
        $element->method('getFieldLayout')->willReturn($this->fakeLayout($layoutId, $fields));
        $element->method('getCpEditUrl')->willReturn($cpEditUrl);
        $element->id = $id;
        $element->title = $title;
        $element->siteId = 1;

        return $element;
    }

    /**
     * A child's label source with no identity of its own — what a block the sync
     * would only add travels with: a throwaway element exposing the block type's
     * field layout.
     *
     * @param array<string, string> $fields handle => field name
     */
    protected function fakeLabelElement(int $layoutId, array $fields): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($this->fakeLayout($layoutId, $fields));

        return $element;
    }

    /**
     * A field layout exposing one custom field per handle => name pair. The id is
     * what the presenter memoizes its child labels by, so each layout needs its
     * own. `getFieldByHandle()` is deliberately left unstubbed (null): it drives
     * the display-only normalizeValue pass, which a mock field can't answer.
     *
     * @param array<string, string> $fields handle => field name
     */
    protected function fakeLayout(int $layoutId, array $fields): FieldLayout
    {
        $craftFields = [];

        foreach ($fields as $handle => $name) {
            $field = $this->createMock(CraftField::class);
            $field->handle = $handle;
            $field->name = $name;
            $craftFields[] = $field;
        }

        $layout = $this->createMock(FieldLayout::class);
        $layout->id = $layoutId;
        $layout->method('getCustomFields')->willReturn($craftFields);

        return $layout;
    }

    protected function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/cp/tests/fixtures/inspector-row.json';

        return json_decode(file_get_contents($path), true);
    }
}
