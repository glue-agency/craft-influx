<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use TDM\Influx\fields\Dropdown;
use TDM\Influx\Tests\unit\Support\FakeLink;

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
            \craft\fields\BaseOptionsField::class,
            Dropdown::craftFieldClass(),
            'Registering against BaseOptionsField is what lets Dropdown/Radio/Checkboxes/MultiSelect share the strategy.',
        );
    }

    public function testPassThroughWhenNoValueMap(): void
    {
        $strategy = $this->build(
            feed: ['region' => 'north'],
            mapping: ['node' => 'region'],
        );
        $this->assertSame('north', $strategy->parseField());
    }

    public function testValueMapRewritesIncomingValue(): void
    {
        $strategy = $this->build(
            feed: ['region' => 'EN'],
            mapping: [
                'node' => 'region',
                'options' => ['valueMap' => ['EN' => 'english', 'NL' => 'dutch']],
            ],
        );
        $this->assertSame('english', $strategy->parseField());
    }

    public function testValueNotInMapPassesThrough(): void
    {
        $strategy = $this->build(
            feed: ['region' => 'south'],
            mapping: [
                'node' => 'region',
                'options' => ['valueMap' => ['EN' => 'english']],
            ],
        );
        $this->assertSame('south', $strategy->parseField());
    }

    public function testReturnsNullWhenNodeMissingAndNoDefault(): void
    {
        $strategy = $this->build(
            feed: [],
            mapping: ['node' => 'region'],
        );
        $this->assertNull($strategy->parseField());
    }

    public function testFallsBackToDefault(): void
    {
        $strategy = $this->build(
            feed: [],
            mapping: ['node' => 'region', 'default' => 'north'],
        );
        $this->assertSame('north', $strategy->parseField());
    }

    private function build(array $feed, array $mapping): Dropdown
    {
        $strategy = new Dropdown();
        $strategy->setContext(
            craftField: null,
            fieldHandle: 'region',
            fieldInfo: $mapping,
            feedData: $feed,
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
        return $strategy;
    }
}
