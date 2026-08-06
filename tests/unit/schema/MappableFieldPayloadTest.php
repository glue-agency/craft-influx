<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\elements\Entry;
use craft\elements\Entry as CraftEntryElement;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * Contract test for the mappable-field descriptor — the second cross-language
 * shape, pinned the same way as the link payload. PHP is the authority
 * ({@see MappableField::toArray()}); the committed fixture is the contract
 * artifact, and the SPA asserts its own assumptions against the same file
 * (`src/web/assets/cp/src/builder/__tests__/mappable-field.contract.test.js`).
 *
 * The natives are produced through the REAL producer ({@see MappingSchemaBuilder::group()})
 * rather than hand-built, so the fixture also pins how a declared node becomes a
 * descriptor. `Craft::t()` with no booted app returns its source string, which is
 * why the labels read as plain English here.
 *
 * If this test fails after a deliberate shape change: update the fixture,
 * `builder/types.js`, and the JS contract test together.
 */
class MappableFieldPayloadTest extends Unit
{
    public function testDescriptorsMatchTheCommittedFixture(): void
    {
        $this->assertEquals(
            $this->fixture(),
            MappableField::toArrays($this->descriptors()),
            'MappableField::toArray() drifted from the committed wire-contract fixture.',
        );
    }

    public function testTheKeySetIsTheSameFourPlusTheRow(): void
    {
        $keys = array_map(static fn(array $descriptor): array => array_keys($descriptor), $this->fixture());

        // Key ORDER is part of the contract: the four keys every descriptor has,
        // `fieldClass` only where there is one, and the row itself last.
        $this->assertSame(['handle', 'name', 'native', 'group', 'mapping'], $keys[0]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'mapping'], $keys[2]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'fieldClass', 'mapping'], $keys[3]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'fieldClass', 'mapping'], $keys[5]);
    }

    public function testACustomFieldCarriesItsStrategysDefaultControl(): void
    {
        // A custom field is not stuck with a plain text default: its strategy
        // declares the control, so an Entries field offers the same element picker
        // a native author does — and still identifies its kind through fieldClass.
        $descriptor = MappableField::toArrays($this->descriptors())[5];

        $this->assertSame(CraftEntriesField::class, $descriptor['fieldClass']);
        $this->assertSame(
            [['type' => SchemaBuilder::ELEMENT, 'elementType' => Entry::class]],
            $descriptor['mapping']['default'],
        );
    }

    public function testARowWithNoCellsCarriesNoCellRegions(): void
    {
        // Absence IS the statement — there is no flag beside it saying so, which is
        // the whole reason the descriptor is this small.
        $descriptor = MappableField::toArrays($this->descriptors())[4];

        $this->assertSame(['extra'], array_keys($descriptor['mapping']));
    }

    public function testEveryReportedFieldIsAMappableOne(): void
    {
        // There's no "reported but not offered" state: what a target reports is
        // exactly what the builder maps, so the serialized list carries no
        // visibility flag to filter on.
        foreach (MappableField::toArrays($this->descriptors()) as $descriptor) {
            $this->assertArrayNotHasKey('offered', $descriptor);
            $this->assertNotEmpty($descriptor['handle']);
        }
    }

    /**
     * The fixture's six descriptors: the three native shapes (a plain text
     * default, a select over declared options, an element picker with extras) plus a
     * plain, a no-cells and an element-defaulted custom field.
     *
     * @return list<MappableField>
     */
    protected function descriptors(): array
    {
        $natives = MappingSchemaBuilder::make()
            ->group('Native', fn(MappingSchemaBuilder $group) => $group
                ->text(['handle' => 'title', 'name' => 'Title'])
                ->select([
                    'handle'  => 'enabled',
                    'name'    => 'Enabled',
                    'options' => ['true' => 'Enabled', 'false' => 'Disabled'],
                ])
                ->element([
                    'handle'      => 'author',
                    'name'        => 'Author',
                    'elementType' => User::class,
                    'extras'      => fn(MappingSchemaBuilder $builder)      => $builder->matchBy([
                        'options' => [
                            ['value' => 'id', 'label' => 'ID'],
                            ['value' => 'email', 'label' => 'Email'],
                        ],
                    ]),
                ]))
            ->toArray();

        return array_merge($natives, [
            MappableField::custom('importId', 'Import ID', 'Content', PlainText::class, [
                'source'  => MappingSchemaBuilder::make()->sourceNode()->toArray(),
                'default' => [['type' => SchemaBuilder::TEXT]],
            ]),
            MappableField::custom('contentBlocks', 'Content blocks', 'Content', Matrix::class, [
                'extra' => [[
                    'type'      => MappingSchemaBuilder::MATRIX_FIELDS,
                    'handle'    => 'blocks',
                    'label'     => 'Text',
                    'blockType' => 'text',
                    'subFields' => [],
                ]],
            ]),
            $this->relationDescriptor(),
        ]);
    }

    /**
     * A custom Entries field, built by the REAL producer — one row from the
     * strategy's own `schema()`, exactly as
     * {@see \GlueAgency\Influx\targets\AbstractElementTarget::customFieldDescriptors()}
     * assembles it. So the fixture pins the field-type-aware default cell, the
     * match-by list per element type AND the sub-field card's rows, rather than a
     * hand-written approximation of them.
     */
    protected function relationDescriptor(): MappableField
    {
        return MappableField::custom(
            handle: 'relatedArticles',
            name: 'Related articles',
            group: 'Content',
            fieldClass: CraftEntriesField::class,
            mapping: $this->relationSchema()->schema($this->createMock(CraftEntriesField::class))->toArray(),
        );
    }

    /**
     * The real Entries strategy with only what needs a booted Craft stubbed: the
     * source-layout walk (one layout, one Entries custom field) and the entry-type
     * lookup its title / slug gating reads. Building the fixture from the producer
     * rather than by hand is what makes it pin the match-by list AND the card's
     * rows — the two things GT-107 reported wrong.
     */
    protected function relationSchema(): Entries
    {
        return new class($this) extends Entries {
            public MappableFieldPayloadTest $test;

            public function __construct(MappableFieldPayloadTest $test)
            {
                $this->test = $test;
            }

            protected function sourceEntryTypes(BaseRelationField $field): array
            {
                return [];
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return [$this->test->fakeLayout()];
            }

            protected function childRowFor(CraftFieldInterface $craftField): array
            {
                return $craftField->handle === 'campus'
                    ? ['default' => ['type' => SchemaBuilder::ELEMENT, 'elementType' => CraftEntryElement::class], 'extra' => []]
                    : ['default' => ['type' => 'text'], 'extra' => []];
            }
        };
    }

    /**
     * A source's field layout carrying one plain-text and one relation custom
     * field, so the fixture pins a row of each editor kind.
     */
    public function fakeLayout(): FieldLayout
    {
        $customFields = [];

        foreach (['blurb' => PlainText::class, 'campus' => CraftEntriesField::class] as $handle => $class) {
            $field = $this->createMock($class);
            $field->handle = $handle;
            $field->name = ucfirst($handle);
            $customFields[] = $field;
        }

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn($customFields);

        return $layout;
    }

    protected function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/cp/tests/fixtures/mappable-field.json';

        return json_decode(file_get_contents($path), true);
    }
}
