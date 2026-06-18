<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\fields\RelationalField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the shared relational apply path (Entries / Users /
 * Categories / Tags / Assets).
 *
 * The contract under test: an empty/null parse must reach the element as an
 * explicit empty array, never null. Craft relation fields read null as "no
 * value supplied — keep the existing relations", so writing null would leave
 * the relation intact; only [] actually detaches the related elements. This
 * is what makes a feed that clears a relation (e.g. remote "start": []) stick.
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

    protected function strategy(): RelationalField
    {
        // RelationalField is abstract only for Field::parse(); apply() — the
        // method under test — is fully defined on the base.
        return new class extends RelationalField {
            public function parse(FieldContext $context): mixed
            {
                return null;
            }
        };
    }

    protected function context(ElementInterface $element): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'starting_moment',
            mapping: FieldMapping::fromConfig('starting_moment', ['node' => 'start']),
            item: new RemoteItem([]),
            link: FakeLink::make(),
            element: $element,
        );
    }
}
