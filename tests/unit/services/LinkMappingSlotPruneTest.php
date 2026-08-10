<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\PlainText;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\services\LinksService;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * The second pass of {@see LinksService::pruneMappings()}: a mapping whose handle
 * is still reported can still hold slots the row no longer renders, because what
 * a row renders is its field strategy's business and that changes underneath
 * stored config — an integration hooked onto a custom field type, a Preparse
 * strategy declaring the value computed, an option the field stopped offering.
 * The leftover slot has no cell to edit it and no cell to clear it, so the save
 * strips it ({@see \GlueAgency\Influx\schema\MappingSlots}).
 *
 * Handle-level pruning is specced in
 * {@see \GlueAgency\Influx\Tests\unit\targets\EntryMappingPruneTest}; these cases
 * all keep the handle and watch what happens inside it.
 */
class LinkMappingSlotPruneTest extends Unit
{
    public function testAStaleNodeIsStrippedFromASubFieldsOnlyRow(): void
    {
        // The reported case: the field rendered one source node, an integration
        // gave it sub-fields instead, and the operator's saved node has no cell.
        $link = FakeLink::make([
            'mappings' => [
                'map_field' => [
                    'node'   => 'coordinates',
                    'fields' => ['lat' => ['node' => 'lat'], 'lng' => ['node' => 'lng']],
                ],
            ],
        ]);

        $this->prune($link, [$this->custom('map_field', $this->subFieldsOnlyRow())]);

        $this->assertSame([
            'map_field' => ['fields' => ['lat' => ['node' => 'lat'], 'lng' => ['node' => 'lng']]],
        ], $link->mappings);
    }

    public function testAStaleOptionIsStrippedWhileTheRestOfTheRowStands(): void
    {
        $link = FakeLink::make([
            'mappings' => [
                'importId' => ['node' => 'id', 'options' => ['mode' => 'url']],
            ],
        ]);

        $this->prune($link, [$this->custom('importId', $this->plainRow())]);

        $this->assertSame(['importId' => ['node' => 'id']], $link->mappings);
    }

    public function testAMappingLeftWithNoWritableSlotIsDroppedEntirely(): void
    {
        // The Preparse shape: a source region that renders copy and no control, so
        // nothing in the stored mapping can be edited or cleared. An empty entry
        // would be noise in Project Config, so the handle goes with it.
        $link = FakeLink::make([
            'mappings' => [
                'computed' => ['node' => 'whatever'],
                'importId' => ['node' => 'id'],
            ],
        ]);

        $this->prune($link, [
            $this->custom('computed', $this->noteOnlyRow()),
            $this->custom('importId', $this->plainRow()),
        ]);

        $this->assertSame(['importId' => ['node' => 'id']], $link->mappings);
    }

    public function testAHealthyRowIsLeftByteForByte(): void
    {
        $mappings = [
            'importId' => ['node' => 'id', 'default' => 'fallback'],
            'ref'      => ['node' => 'ref', 'options' => ['match' => 'title']],
        ];
        $link = FakeLink::make(['mappings' => $mappings]);

        $this->prune($link, [
            $this->custom('importId', $this->plainRow()),
            $this->custom('ref', $this->matchByRow()),
        ]);

        $this->assertSame($mappings, $link->mappings);
    }

    public function testTheSlotPassIsSkippedOnANativesOnlySurface(): void
    {
        // Same bail as the handle pass: a natives-only surface means the link's
        // criteria didn't resolve, so nothing about a custom row is knowably stale.
        $mappings = ['map_field' => ['node' => 'coordinates']];
        $link = FakeLink::make(['mappings' => $mappings]);

        $this->prune($link, [MappableField::native('title', 'Title', 'Native', $this->plainRow())]);

        $this->assertSame($mappings, $link->mappings);
    }

    public function testPruningIsIdempotent(): void
    {
        $link = FakeLink::make([
            'mappings' => ['map_field' => ['node' => 'coordinates', 'fields' => ['lat' => ['node' => 'lat']]]],
        ]);
        $surface = [$this->custom('map_field', $this->subFieldsOnlyRow())];

        $this->prune($link, $surface);
        $once = $link->mappings;
        $this->prune($link, $surface);

        $this->assertSame($once, $link->mappings);
    }

    /** A row rendering a source node and a text default — what most field types declare. */
    protected function plainRow(): array
    {
        return MappingSchemaBuilder::make()->mapping(['source' => true, 'default' => true])->toArray();
    }

    /** A row whose value comes from sub-field rows, with no cell of its own. */
    protected function subFieldsOnlyRow(): array
    {
        return MappingSchemaBuilder::make()
            ->mapping(['extra' => fn(MappingSchemaBuilder $b) => $b->subFields()])
            ->toArray();
    }

    /** A row that renders copy instead of a control — the Preparse shape. */
    protected function noteOnlyRow(): array
    {
        return MappingSchemaBuilder::make()
            ->mapping(['source' => fn(MappingSchemaBuilder $b) => $b->note(['text' => 'Computed.'])])
            ->toArray();
    }

    /** A relation-shaped row: source node plus a `match` option. */
    protected function matchByRow(): array
    {
        return MappingSchemaBuilder::make()->mapping([
            'source' => true,
            'extra'  => fn(MappingSchemaBuilder $b)  => $b->matchBy(['options' => []]),
        ])->toArray();
    }

    /**
     * A custom-field descriptor carrying the given row. Custom rather than native
     * so the surface arms the prune.
     */
    protected function custom(string $handle, array $row): MappableField
    {
        return MappableField::custom($handle, $handle, 'Content', PlainText::class, $row);
    }

    /**
     * Run the pruner against a stated field surface, through the service's own
     * target seam so no Craft boot is needed.
     *
     * @param list<MappableField> $fields
     */
    protected function prune(Link $link, array $fields): void
    {
        $service = new class($this->target($fields)) extends LinksService {
            protected ?ElementTargetInterface $target = null;

            public function __construct(?ElementTargetInterface $target)
            {
                parent::__construct();
                $this->target = $target;
            }

            public function prune(Link $link): void
            {
                $this->pruneMappings($link);
            }

            protected function targetForLink(Link $link): ?ElementTargetInterface
            {
                return $this->target;
            }
        };

        $service->prune($link);
    }

    /**
     * A target that reports exactly the given field surface.
     *
     * @param list<MappableField> $fields
     */
    protected function target(array $fields): AbstractElementTarget
    {
        return new class($fields) extends AbstractElementTarget {
            /** @var list<MappableField> */
            protected array $fields = [];

            /** @param list<MappableField> $fields */
            public function __construct(array $fields)
            {
                $this->fields = $fields;
            }

            public static function elementType(): string
            {
                return Entry::class;
            }

            public function getMappableFields(Link $link): array
            {
                return $this->fields;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new RuntimeException('not needed');
            }
        };
    }
}
