<?php

namespace GlueAgency\Influx\Tests\unit\integrations\preparse;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\integrations\preparse\PreparseField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Preparse strategy, whose whole job is to refuse.
 *
 * The field's value is a Twig template the plugin re-renders on every element
 * save, so a mapped one was discarded AND churned — the stored value being the
 * rendered template while the incoming one was the feed's, which never matched.
 */
class PreparseFieldTest extends Unit
{
    public function testItIsKeyedToThePreparseFieldClass(): void
    {
        $this->assertSame(
            'jalendport\preparse\fields\PreparseFieldType',
            PreparseField::craftFieldClass(),
            'Keyed by class STRING so an install without the plugin never hits it.',
        );
    }

    /**
     * A source region holding nothing but a note, and no default region at all —
     * the row keeps its label rather than vanishing, so an operator looking for the
     * field finds it and learns why it isn't mappable.
     *
     * That placement is what distinguishes it from a Matrix, which declares no
     * source region at all: there IS a cell here, and what's in it says why nothing
     * can be mapped onto the field.
     *
     * The message is an ordinary note node — the same one Matrix and Table use for
     * their "nothing to map yet" copy — so the row renders it through the one
     * renderer that knows how, rather than through a meta key of its own.
     */
    public function testTheSourceCellHoldsNothingButANote(): void
    {
        $schema = (new PreparseField())->schema($this->createMock(CraftFieldInterface::class));
        $source = $schema->toArray()['source'];

        $this->assertCount(1, $source);
        $this->assertSame(SchemaBuilder::NOTE, $source[0]['type']);
        $this->assertNotEmpty($source[0]['text']);
        $this->assertFalse($schema->has('default'), 'A default is what a sync falls back to, and no sync writes this.');
    }

    /**
     * THE guard. Never addressed, whatever the feed carries and whatever a
     * mapping stored before this strategy existed says — so the applier skips the
     * row and no write is ever attempted.
     */
    public function testItIsNeverAddressedEvenWithAMappedNodeCarryingAValue(): void
    {
        $strategy = new PreparseField();

        $this->assertFalse($strategy->addressed($this->context(['computed' => 'anything'])));
        $this->assertFalse($strategy->addressed($this->context([], ['default' => 'x', 'useDefault' => true])));
    }

    public function testParseYieldsNothingIfReachedOutOfBand(): void
    {
        $this->assertNull((new PreparseField())->parse($this->context(['computed' => 'anything'])));
    }

    private function context(array $feed, array $mapping = []): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'computed',
            mapping: FieldMapping::fromConfig('computed', $mapping + ['node' => 'computed']),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
