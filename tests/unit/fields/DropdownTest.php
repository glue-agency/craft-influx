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
 *   options.match: 'value' (default — pass through) or 'label' (translate
 *   the feed's human-readable labels to stored option values, trimmed and
 *   case-insensitive; unmatched values pass through).
 *
 * Validating that the resulting value is part of the configured option set
 * is Craft's job, not the strategy's. Label lookups here go through a
 * {@see Dropdown::labelToValueMap()} override — the no-boot suite can't
 * build a real BaseOptionsField.
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

    public function testPassesThroughByDefault(): void
    {
        $context = $this->context(
            feed: ['region' => 'north'],
            mapping: ['node' => 'region'],
        );
        $this->assertSame('north', $this->strategy()->parse($context));
    }

    public function testLabelMatchTranslatesToOptionValue(): void
    {
        $context = $this->context(
            feed: ['epc' => 'Zeer energiezuinig (A+)'],
            mapping: [
                'node'    => 'epc',
                'options' => ['match' => 'label'],
            ],
        );
        $this->assertSame('aPlus', $this->strategy()->parse($context));
    }

    public function testLabelMatchIsTrimmedAndCaseInsensitive(): void
    {
        $context = $this->context(
            feed: ['epc' => '  zeer energiezuinig (a+) '],
            mapping: [
                'node'    => 'epc',
                'options' => ['match' => 'label'],
            ],
        );
        $this->assertSame('aPlus', $this->strategy()->parse($context));
    }

    public function testUnmatchedLabelPassesThrough(): void
    {
        $context = $this->context(
            feed: ['epc' => 'Onbekend'],
            mapping: [
                'node'    => 'epc',
                'options' => ['match' => 'label'],
            ],
        );
        $this->assertSame('Onbekend', $this->strategy()->parse($context));
    }

    public function testLabelMatchTranslatesEachArrayElement(): void
    {
        // Checkboxes / MultiSelect resolve to a list — each element is
        // translated on its own; unmatched ones pass through.
        $context = $this->context(
            feed: ['tags' => ['Zeer energiezuinig (A+)', 'Onbekend']],
            mapping: [
                'node'    => 'tags',
                'options' => ['match' => 'label'],
            ],
        );
        $this->assertSame(['aPlus', 'Onbekend'], $this->strategy()->parse($context));
    }

    public function testReturnsNullWhenNodeMissingAndNoDefault(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'region'],
        );
        $this->assertNull($this->strategy()->parse($context));
    }

    public function testFallsBackToDefault(): void
    {
        $context = $this->context(
            feed: [],
            mapping: ['node' => 'region', 'default' => 'north'],
        );
        $this->assertSame('north', $this->strategy()->parse($context));
    }

    /**
     * A Dropdown whose option set is stubbed — the real one reads the Craft
     * field's configured options, which need a booted Craft.
     */
    private function strategy(): Dropdown
    {
        return new class() extends Dropdown {
            protected function labelToValueMap(FieldContext $context): array
            {
                return [
                    'zeer energiezuinig (a+)' => 'aPlus',
                    'energiezuinig (a)'       => 'a',
                ];
            }
        };
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
