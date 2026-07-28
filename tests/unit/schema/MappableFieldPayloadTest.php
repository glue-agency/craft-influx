<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use craft\elements\User;
use craft\fields\Matrix;
use craft\fields\PlainText;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\schema\SchemaBuilder;

/**
 * Contract test for the mappable-field descriptor — the second cross-language
 * shape, pinned the same way as the link payload. PHP is the authority
 * ({@see MappableField::toArray()}); the committed fixture is the contract
 * artifact, and the SPA asserts its own assumptions against the same file
 * (`src/web/assets/cp/src/builder/__tests__/mappable-field.contract.test.js`).
 *
 * The natives are produced through the REAL producer ({@see SchemaBuilder::group()})
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

    public function testOptionalKeysAreOmittedRatherThanNulled(): void
    {
        $keys = array_map(static fn(array $descriptor): array => array_keys($descriptor), $this->fixture());

        // Key ORDER is part of the contract: the five always-present keys lead,
        // then only the optional keys that actually carry something.
        $this->assertSame(['handle', 'name', 'native', 'group', 'defaultType'], $keys[0]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'defaultType', 'options'], $keys[1]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'defaultType', 'elementType', 'fieldMeta'], $keys[2]);
        $this->assertSame(['handle', 'name', 'native', 'group', 'defaultType', 'fieldClass', 'fieldMeta'], $keys[3]);
    }

    public function testCustomFieldsAlwaysCarryFieldMetaEvenWhenEmpty(): void
    {
        // An empty extras schema is still a schema — the key stays, so the SPA
        // never has to distinguish "absent" from "empty" for a custom field.
        $descriptor = MappableField::custom('importId', 'Import ID', 'Content', PlainText::class, ['schema' => []])->toArray();

        $this->assertArrayHasKey('fieldMeta', $descriptor);
        $this->assertSame(['schema' => []], $descriptor['fieldMeta']);
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
     * The fixture's five descriptors: the three native shapes (plain text,
     * select with options, element with extras) plus a plain and a
     * subfields-only custom field.
     *
     * @return list<MappableField>
     */
    protected function descriptors(): array
    {
        $natives = SchemaBuilder::make()
            ->group('Native', fn(SchemaBuilder $group) => $group
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
                    'extras'      => fn(SchemaBuilder $builder)      => $builder->matchBy([
                        'options' => [
                            ['value' => 'id', 'label' => 'ID'],
                            ['value' => 'email', 'label' => 'Email'],
                        ],
                    ]),
                ]))
            ->toArray();

        return array_merge($natives, [
            MappableField::custom('importId', 'Import ID', 'Content', PlainText::class, ['schema' => []]),
            MappableField::custom('contentBlocks', 'Content blocks', 'Content', Matrix::class, [
                'schema' => [
                    [
                        'type'      => SchemaBuilder::MATRIX_FIELDS,
                        'handle'    => 'blocks',
                        'label'     => 'Text',
                        'blockType' => 'text',
                        'subFields' => [],
                    ],
                ],
                'subfieldsOnly' => true,
            ]),
        ]);
    }

    protected function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/src/web/assets/cp/tests/fixtures/mappable-field.json';

        return json_decode(file_get_contents($path), true);
    }
}
