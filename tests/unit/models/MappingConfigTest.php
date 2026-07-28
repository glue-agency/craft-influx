<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\MappingCollection;

/**
 * The producing half of the mapping value objects: {@see FieldMapping::toConfig()}
 * and {@see MappingCollection::toConfig()}, which own the
 * `{node, default, useDefault, options, fields, nativeFields, blocks}` shape every
 * writer used to hand-assemble.
 *
 * Two properties matter, and both are what keeps stored config byte-identical:
 *  - the round trip `fromConfig() -> toConfig()` is lossless for any config the
 *    stored shape can actually hold (proved per slot below);
 *  - a slot carrying nothing is omitted by the ONE stored-config empty rule
 *    ({@see Link::isEmptyConfigValue()}), the same rule
 *    {@see Link::getConfig()} applies at the gate — so a mapping converges on
 *    the gate's shape earlier, never on a different one.
 */
class MappingConfigTest extends Unit
{
    /**
     * @dataProvider storedConfigs
     */
    public function testStoredConfigsRoundTripLosslessly(array $config): void
    {
        $this->assertSame(
            $config,
            FieldMapping::fromConfig('someField', $config)->toConfig(),
            'A stored mapping config must survive fromConfig() -> toConfig() unchanged, key order included.',
        );
    }

    /**
     * Every slot combination the stored shape holds, in the canonical key order
     * toConfig() emits.
     */
    public function storedConfigs(): array
    {
        return [
            'node only'         => [['node' => 'external_id']],
            'node and default'  => [['node' => 'title', 'default' => 'Untitled']],
            'use default'       => [['useDefault' => true, 'default' => '12']],
            'options'           => [['node' => 'related.slug', 'options' => ['match' => 'slug', 'create' => '1']]],
            'nested options'    => [['node' => 'related.slug', 'options' => ['group' => ['section' => 'news']]]],
            'sub fields'        => [['node' => 'image.url', 'fields' => ['alt' => ['node' => 'image.alt']]]],
            'native sub fields' => [['node' => 'image.url', 'nativeFields' => ['title' => ['node' => 'image.name']]]],
            'blocks'            => [['blocks' => ['text' => ['fields' => ['body' => ['node' => 'body']]]]]],
            'everything'        => [[
                'node'         => 'related.slug',
                'useDefault'   => true,
                'default'      => '12',
                'options'      => ['match' => 'slug'],
                'fields'       => ['summary' => ['node' => 'related.summary']],
                'nativeFields' => ['title' => ['node' => 'related.title']],
                'blocks'       => ['text' => ['fields' => ['body' => ['node' => 'body']]]],
            ]],
            // Falsy-but-real defaults are values, not emptiness: only null and ''
            // count as "no default" (mirroring Link::getConfig()).
            'zero default'        => [['node' => 'count', 'default' => 0]],
            'zero string default' => [['node' => 'count', 'default' => '0']],
        ];
    }

    public function testEmptySlotsAreOmitted(): void
    {
        $config = FieldMapping::make(
            handle: 'title',
            node: null,
            default: '',
            useDefault: false,
            options: [],
            fields: [],
            nativeFields: [],
            blocks: [],
        )->toConfig();

        $this->assertSame([], $config, 'A mapping with nothing in any slot emits nothing.');
    }

    public function testFalseUseDefaultIsOmittedRatherThanStoredOff(): void
    {
        // The flag is only ever meaningful when on — an off flag is the absence
        // of the "— use default —" choice, which is what a missing key says.
        $this->assertSame(
            ['node' => 'title'],
            FieldMapping::make('title', node: 'title', useDefault: false)->toConfig(),
        );
    }

    public function testEmptyStringDefaultIsOmitted(): void
    {
        // Consistent with FieldMapping::resolve(), which treats an empty-string
        // default as no default at all.
        $this->assertSame(
            ['node' => 'title'],
            FieldMapping::make('title', node: 'title', default: '')->toConfig(),
        );
    }

    public function testCollectionRoundTripsAWholeMappingsConfig(): void
    {
        $mappings = [
            'importId' => ['node' => 'external_id'],
            'title'    => ['node' => 'name', 'default' => 'Untitled'],
            'category' => ['useDefault' => true, 'default' => '12'],
        ];

        $this->assertSame($mappings, MappingCollection::fromConfig($mappings)->toConfig());
    }

    public function testCollectionKeepsInsertionOrder(): void
    {
        $collection = MappingCollection::of([
            'zeta'  => FieldMapping::make('zeta', node: 'z'),
            'alpha' => FieldMapping::make('alpha', node: 'a'),
        ]);

        $this->assertSame(['zeta', 'alpha'], array_keys($collection->toConfig()));
    }

    public function testCollectionDropsMappingsThatEmitNothing(): void
    {
        $collection = MappingCollection::of([
            'kept'    => FieldMapping::make('kept', node: 'a'),
            'dropped' => FieldMapping::make('dropped'),
        ]);

        $this->assertSame(['kept' => ['node' => 'a']], $collection->toConfig());
    }

    public function testMappingsSurviveTheProjectConfigGateUnchanged(): void
    {
        // The gate only strips a link's own top-level fields; whatever the VO
        // produced for `mappings` reaches Project Config verbatim.
        $mappings = [
            'importId' => ['node' => 'external_id'],
            'enabled'  => ['useDefault' => true, 'default' => 'true'],
        ];

        $link = new Link();
        $link->handle = 'articles';
        $link->name = 'Articles';
        $link->elementType = 'craft\elements\Entry';
        $link->endpoint = 'https://example.test/articles';
        $link->mappings = MappingCollection::fromConfig($mappings)->toConfig();

        $this->assertSame($mappings, $link->getConfig()['mappings']);
    }

    /**
     * @dataProvider emptyValues
     */
    public function testEmptyConfigValueRule(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Link::isEmptyConfigValue($value));
    }

    public function emptyValues(): array
    {
        return [
            'null'         => [null, true],
            'empty string' => ['', true],
            'false'        => [false, true],
            'empty array'  => [[], true],
            'zero'         => [0, false],
            'zero string'  => ['0', false],
            'true'         => [true, false],
            'string'       => ['x', false],
            'array'        => [['a'], false],
        ];
    }
}
