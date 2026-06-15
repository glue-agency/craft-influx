<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\BaseOptionsField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the option-based field strategy: Dropdown, RadioButtons,
 * Checkboxes, MultiSelect. Registered against `BaseOptionsField` so a single
 * strategy covers the whole family.
 *
 *   options.valueMap: optional remote → local rewrite. Values not present
 *                     in the map pass through unchanged.
 *
 * Validating that the resulting value is part of the configured option set
 * is Craft's job, not the strategy's.
 */
class DropdownTest extends Unit
{
    public function testCraftFieldClassIsBaseOptionsField(): void
    {
        $this->assertSame(
            BaseOptionsField::class,
            Dropdown::craftFieldClass(),
            'Registering against BaseOptionsField is what lets Dropdown/Radio/Checkboxes/MultiSelect share the strategy.',
        );
    }

    public function testPassThroughWhenNoValueMap(): void
    {
        $context = $this->context(
            feed: ['region' => 'north'],
            mapping: ['node' => 'region'],
        );
        $this->assertSame('north', (new Dropdown())->parse($context));
    }

    public function testValueMapRewritesIncomingValue(): void
    {
        $context = $this->context(
            feed: ['region' => 'EN'],
            mapping: [
                'node'    => 'region',
                'options' => ['valueMap' => ['EN' => 'english', 'NL' => 'dutch']],
            ],
        );
        $this->assertSame('english', (new Dropdown())->parse($context));
    }

    public function testValueNotInMapPassesThrough(): void
    {
        $context = $this->context(
            feed: ['region' => 'south'],
            mapping: [
                'node'    => 'region',
                'options' => ['valueMap' => ['EN' => 'english']],
            ],
        );
        $this->assertSame('south', (new Dropdown())->parse($context));
    }

    public function testReturnsNullWhenNodeMissingAndNoDefault(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'region'],
        );
        $this->assertNull((new Dropdown())->parse($context));
    }

    public function testFallsBackToDefault(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'region', 'default' => 'north'],
        );
        $this->assertSame('north', (new Dropdown())->parse($context));
    }

    private function context(array $feed, array $mapping): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'region',
            mapping: FieldMapping::fromConfig('region', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
