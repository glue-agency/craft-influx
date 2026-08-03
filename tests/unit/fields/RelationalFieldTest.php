<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Entries as CraftEntriesField;
use GlueAgency\Influx\fields\RelationalField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the shared relational apply path (Entries / Users /
 * Categories / Tags / Assets).
 *
 * Two contracts under test.
 *
 * The write: an empty/null parse must reach the element as an explicit empty
 * array, never null. Craft relation fields read null as "no value supplied —
 * keep the existing relations", so writing null would leave the relation
 * intact; only [] actually detaches the related elements. This is what makes a
 * feed that clears a relation (e.g. remote "start": []) stick.
 *
 * The comparison: what the field currently relates has to be read the way Craft
 * itself reads it for serialization. The value Craft hands back is a query
 * carrying its own defaults, so a disabled or other-site relation drops out of a
 * plain `ids()` — the stored side under-reports, the sets can never match, and
 * the field reads as changed on every sync (a save and a revision per run, on an
 * element the feed never touched).
 */
class RelationalFieldTest extends Unit
{
    public function testNullParseIsWrittenAsEmptyArrayToClearTheRelation(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->expects($this->once())
            ->method('setFieldValue')
            ->with('starting_moment', []);

        $this->strategy()->apply($this->context($element), null);
    }

    public function testNonEmptyIdsPassThroughUnchanged(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->expects($this->once())
            ->method('setFieldValue')
            ->with('starting_moment', [12, 34]);

        $this->strategy()->apply($this->context($element), [12, 34]);
    }


    public function testAnUnchangedRelationReadsAsUnchanged(): void
    {
        $strategy = $this->strategy();

        $this->assertFalse($this->differs($strategy, [12, 34], [12, 34]));
        $this->assertTrue($this->differs($strategy, [12, 34], [34, 12]), 'Relations persist their order, so a reorder is a real change.');
        $this->assertTrue($this->differs($strategy, [12], [12, 34]));
    }

    public function testTheStoredSideIsReadUnfilteredSoADisabledRelationStillCounts(): void
    {
        // The regression: a query left on its defaults reports only enabled
        // elements in one site, so an element related to a disabled entry looked
        // changed on every run.
        $query = $this->relationQuery([12, 34]);
        $query->expects($this->once())->method('status')->with(null)->willReturnSelf();
        $query->expects($this->once())->method('drafts')->with(null)->willReturnSelf();
        $query->expects($this->once())->method('site')->with('*')->willReturnSelf();
        $query->expects($this->once())->method('unique')->willReturnSelf();
        $query->expects($this->once())->method('limit')->with(null)->willReturnSelf();

        $this->assertFalse($this->differs($this->strategy(), $query, [12, 34]));
    }

    public function testClearingIsAChangeOnlyWhenSomethingIsRelated(): void
    {
        $this->assertTrue($this->differs($this->strategy(), [12], []));
        $this->assertFalse($this->differs($this->strategy(), [], []));
        $this->assertFalse($this->differs($this->strategy(), [], null));
    }

    public function testAHierarchyMaintainingFieldIsComparedAsASubset(): void
    {
        // Craft fills the gaps in a structured relation on save, so the stored
        // side holds ancestors the feed never sent. Equality could never hold —
        // what a change means here is "the feed asks for an id that isn't there".
        $field = new CraftEntriesField();
        $field->maintainHierarchy = true;

        $this->assertFalse($this->differs($this->strategy(), [7, 12, 34], [12, 34], $field));
        $this->assertTrue($this->differs($this->strategy(), [7, 12], [12, 34], $field));
        // A cleared feed is still a change while anything is related.
        $this->assertTrue($this->differs($this->strategy(), [7, 12], [], $field));
        $this->assertFalse($this->differs($this->strategy(), [], [], $field));
    }

    public function testAResolvedCollectionIsTakenAsItStands(): void
    {
        // Craft 5 hands an eager-loaded field back as a collection: it holds
        // what it holds, so there is no query to relax.
        $collection = new class([12, 34]) {
            /** @var list<int> */
            public array $ids = [];

            public function __construct(array $ids)
            {
                $this->ids = $ids;
            }

            public function ids(): array
            {
                return $this->ids;
            }
        };

        $this->assertFalse($this->differs($this->strategy(), $collection, [12, 34]));
    }

    /**
     * @param mixed $current An id list (wrapped in a query stub), a query or a
     * collection — whatever the spec is speaking about.
     */
    protected function differs(RelationalField $strategy, mixed $current, mixed $incoming, ?CraftEntriesField $craftField = null): bool
    {
        if (is_array($current)) {
            $current = $this->relationQuery($current);
        }

        $expose = new class() extends RelationalField {
            public function parse(FieldContext $context): mixed
            {
                return null;
            }

            public function exposedValueDiffers(FieldContext $context, mixed $current, mixed $incoming): bool
            {
                return $this->valueDiffers($context, $current, $incoming);
            }
        };

        return $expose->exposedValueDiffers(
            $this->context($this->createMock(ElementInterface::class), $craftField),
            $current,
            $incoming,
        );
    }

    /** A relation field's value: a query that relaxes to itself and reports ids. */
    protected function relationQuery(array $ids): ElementQueryInterface
    {
        $query = $this->createMock(ElementQueryInterface::class);
        $query->method('status')->willReturnSelf();
        $query->method('drafts')->willReturnSelf();
        $query->method('site')->willReturnSelf();
        $query->method('unique')->willReturnSelf();
        $query->method('limit')->willReturnSelf();
        $query->method('ids')->willReturn($ids);

        return $query;
    }

    protected function strategy(): RelationalField
    {
        // RelationalField is abstract only for Field::parse(); apply() — the
        // method under test — is fully defined on the base.
        return new class() extends RelationalField {
            public function parse(FieldContext $context): mixed
            {
                return null;
            }
        };
    }

    protected function context(ElementInterface $element, ?CraftEntriesField $craftField = null): FieldContext
    {
        return new FieldContext(
            craftField: $craftField,
            handle: 'starting_moment',
            mapping: FieldMapping::fromConfig('starting_moment', ['node' => 'start']),
            item: new RemoteItem([]),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
