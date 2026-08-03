<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeElement;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * What a NATIVE-attribute row reports about itself to the inspectors.
 *
 * The row used to carry no parsed value at all. For an attribute read from a
 * node that went unnoticed — the incoming column showed the feed value, and the
 * two columns would have read the same anyway — but a mapping set to "use
 * default" has no node, so BOTH columns were empty and the row looked like it
 * had done nothing, on a run where it had written the default.
 *
 * The reported value is the RESOLUTION (node value, else default), not the
 * stored form: the target coerces per attribute from there, and only it knows
 * that shape.
 */
class NativeRowReportingTest extends Unit
{
    public function testAUseDefaultRowReportsTheDefaultItApplied(): void
    {
        $row = $this->row(['default' => 'Jan Janssens', 'useDefault' => true]);

        $this->assertTrue($row->usedDefault);
        $this->assertSame('Jan Janssens', $row->parsedValue);
        // Its raw value stays empty — there is no node to have read one from.
        $this->assertNull($row->rawValue);
    }

    public function testANodeMappedRowReportsWhatTheNodeResolvedTo(): void
    {
        $row = $this->row(['node' => 'author_name']);

        $this->assertFalse($row->usedDefault);
        $this->assertSame('Ada Lovelace', $row->rawValue);
        $this->assertSame('Ada Lovelace', $row->parsedValue);
    }

    public function testANodeThatFallsBackToTheDefaultReportsTheDefault(): void
    {
        // The node is there but empty, which is what makes the default apply —
        // and the row says which value the target was handed, not just what the
        // feed carried.
        $row = $this->row(['node' => 'author_name', 'default' => 'Fallback'], ['author_name' => '']);

        $this->assertSame('', $row->rawValue);
        $this->assertSame('Fallback', $row->parsedValue);
    }

    public function testAnUnaddressedRowReportsNoValueAtAll(): void
    {
        // An absent node with no explicit "use default" addresses nothing, so
        // nothing was applied and there is nothing to have parsed.
        $row = $this->row(['node' => 'missing', 'default' => 'Fallback']);

        $this->assertTrue($row->unaddressed);
        $this->assertNull($row->parsedValue);
    }

    /**
     * One native row, applied through the real applier against a target that
     * reports every write as a change.
     *
     * @param array<string, mixed> $mappingConfig
     * @param array<string, mixed> $item
     */
    protected function row(array $mappingConfig, array $item = ['author_name' => 'Ada Lovelace']): MappingResult
    {
        $rows = (new MappingApplier())->apply(
            $this->context($mappingConfig),
            $this->element(),
            new RemoteItem($item),
        );

        return $rows[0];
    }

    /** @param array<string, mixed> $mappingConfig */
    protected function context(array $mappingConfig): SyncContext
    {
        $target = new class() extends AbstractElementTarget {
            public static function elementType(): string
            {
                return ElementInterface::class;
            }

            public function nativeHandles(Link $link): array
            {
                return ['author'];
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new \LogicException('The row walk builds nothing.');
            }

            public function applyNativeAttribute(
                SyncContext $context,
                ElementInterface $element,
                string $handle,
                RemoteItem $item,
                FieldMapping $mapping,
            ): bool {
                return true;
            }
        };

        return new SyncContext(
            link: FakeLink::make(['mappings' => ['author' => $mappingConfig]]),
            target: $target,
        );
    }

    /** The bootless suite's real-element stand-in, plus the attribute mapped. */
    protected function element(): ElementInterface
    {
        return new class() extends FakeElement {
            public ?string $author = null;
        };
    }
}
