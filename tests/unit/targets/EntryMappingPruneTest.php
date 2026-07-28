<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\PlainText;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\services\LinksService;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * The other half of the hidden-native decision: a native the entry type stopped
 * reporting ({@see EntryNativeVisibilityTest}) is unmappable, so the link's
 * stored mapping for it is dropped by
 * {@see LinksService::pruneUnknownMappings()} — the same path that already drops
 * a custom field removed from its layout. That happens on the NEXT save of the
 * link (a builder save or a Feed Me import), not on a project-config apply.
 *
 * Pure: the target lookup goes through the service's own seam, so the reported
 * field surface is stated per case and no Craft boot is needed.
 */
class EntryMappingPruneTest extends Unit
{
    public function testAMappingForAHiddenNativeIsPruned(): void
    {
        $link = FakeLink::make([
            'mappings' => [
                'title'    => ['node' => 'name'],
                'importId' => ['node' => 'id'],
            ],
        ]);

        // The entry type hides its Title field, so the target no longer reports
        // `title` at all — exactly like the layout-removed custom field below.
        $this->prune($link, $this->surfaceWithout('title'));

        $this->assertSame(['importId'], array_keys($link->mappings));
    }

    public function testAMappingForALayoutRemovedCustomFieldIsPruned(): void
    {
        $link = FakeLink::make([
            'mappings' => [
                'title'   => ['node' => 'name'],
                'dropped' => ['node' => 'gone'],
            ],
        ]);

        $this->prune($link, $this->surface());

        $this->assertSame(['title'], array_keys($link->mappings));
    }

    public function testStillReportedHandlesSurvive(): void
    {
        $mappings = [
            'title'    => ['node' => 'name'],
            'importId' => ['node' => 'id'],
        ];
        $link = FakeLink::make(['mappings' => $mappings]);

        $this->prune($link, $this->surface());

        $this->assertSame($mappings, $link->mappings, 'Nothing unmappable, so nothing may change.');
    }

    public function testANativesOnlySurfaceNeverPrunes(): void
    {
        // A natives-only surface means the link's criteria didn't resolve, so the
        // custom-field mappings aren't knowably stale — pruning then would wipe a
        // half-configured link.
        $mappings = ['importId' => ['node' => 'id'], 'body' => ['node' => 'content']];
        $link = FakeLink::make(['mappings' => $mappings]);

        $this->prune($link, [MappableField::native('title', 'Title', 'Native')]);

        $this->assertSame($mappings, $link->mappings);
    }

    public function testNoTargetLeavesMappingsAlone(): void
    {
        $mappings = ['title' => ['node' => 'name']];
        $link = FakeLink::make(['mappings' => $mappings]);

        $this->prune($link, null);

        $this->assertSame($mappings, $link->mappings);
    }

    /**
     * The reported field surface of a resolved entry type: the natives plus one
     * custom field, so pruning is armed.
     *
     * @return list<MappableField>
     */
    protected function surface(): array
    {
        return [
            MappableField::native('title', 'Title', 'Native'),
            MappableField::native('slug', 'Slug', 'Native'),
            MappableField::custom('importId', 'Import ID', 'Content', PlainText::class, ['schema' => []]),
        ];
    }

    /**
     * The same surface minus one handle — a hidden native, or a custom field
     * removed from the layout.
     *
     * @return list<MappableField>
     */
    protected function surfaceWithout(string $handle): array
    {
        return array_values(array_filter(
            $this->surface(),
            static fn(MappableField $field): bool => $field->handle !== $handle,
        ));
    }

    /**
     * Run the pruner against a stated field surface. `$fields` null stands for
     * "no target registered for this element type".
     *
     * @param list<MappableField>|null $fields
     */
    protected function prune(Link $link, ?array $fields): void
    {
        $target = $fields === null ? null : $this->target($fields);

        $service = new class($target) extends LinksService {
            protected ?ElementTargetInterface $target = null;

            public function __construct(?ElementTargetInterface $target)
            {
                parent::__construct();
                $this->target = $target;
            }

            public function prune(Link $link): void
            {
                $this->pruneUnknownMappings($link);
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
