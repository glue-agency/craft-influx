<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\ButtonGroup;
use craft\fields\Checkboxes;
use craft\fields\Dropdown as CraftDropdownField;
use craft\fields\MultiSelect;
use craft\fields\RadioButtons;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use ReflectionClass;

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

    public function testAPickedDefaultIsNotLabelTranslated(): void
    {
        // The default is picked from the field's own options, so it already IS a
        // stored value — putting it through `match: label` (which describes FEED
        // values) would translate a value that only looks like a label.
        $context = $this->context(
            feed: [],
            mapping: [
                'useDefault' => true,
                'default'    => 'Energiezuinig (A)',
                'options'    => ['match' => 'label'],
            ],
        );
        $this->assertSame('Energiezuinig (A)', $this->strategy()->parse($context));
    }

    public function testAFallenBackDefaultIsNotLabelTranslatedEither(): void
    {
        $context = $this->context(
            feed: ['epc' => ''],
            mapping: [
                'node'    => 'epc',
                'default' => 'Energiezuinig (A)',
                'options' => ['match' => 'label'],
            ],
        );
        $this->assertSame('Energiezuinig (A)', $this->strategy()->parse($context));
    }

    public function testTheDefaultCellIsASelectOverTheFieldsOwnOptions(): void
    {
        // Optgroup headings carry no value — they're skipped, the same rows
        // labelToValueMap() ignores.
        $field = $this->createMock(CraftDropdownField::class);
        $field->options = [
            ['optgroup' => 'Energy'],
            ['label' => 'Zeer energiezuinig (A+)', 'value' => 'aPlus'],
            ['label' => 'Energiezuinig (A)', 'value' => 'a'],
        ];

        $this->assertSame(
            [
                'type'    => SchemaBuilder::SELECT,
                'options' => [
                    ['value' => 'aPlus', 'label' => 'Zeer energiezuinig (A+)'],
                    ['value' => 'a',     'label' => 'Energiezuinig (A)'],
                ],
                // The search box and the "nothing picked" sentinel every default
                // cell's select carries — declared once, by the preset. The
                // sentinel sits beside the field's values, never among them.
                'searchable'        => true,
                'searchPlaceholder' => 'Search options…',
                'sentinelOptions'   => [['value' => '', 'label' => '— no default —']],
            ],
            $this->defaultCell(new Dropdown(), $field),
        );
    }

    /**
     * This one strategy covers the whole BaseOptionsField family, half of which
     * holds a LIST — Checkboxes and MultiSelect store MultiOptionsFieldData. They
     * used to be offered the same single-value default picker as a Dropdown, so a
     * default could only ever set one of the boxes.
     */
    public function testAMultiValueFlavourGetsAMultiSelectDefault(): void
    {
        $field = $this->createMock(Checkboxes::class);
        $field->options = [['label' => 'Red', 'value' => 'red']];

        $cell = $this->defaultCell($this->multiStrategy(), $field);

        $this->assertSame(SchemaBuilder::MULTI_SELECT, $cell['type']);
        $this->assertSame(
            [['value' => 'red', 'label' => 'Red']],
            $cell['options'],
            'The field’s own options, with no blank lead: a multi picker says "none" by having nothing picked.',
        );
    }

    public function testASingleValueFlavourKeepsASingleSelectDefault(): void
    {
        $field = $this->createMock(CraftDropdownField::class);
        $field->options = [['label' => 'Red', 'value' => 'red']];

        $this->assertSame(SchemaBuilder::SELECT, $this->defaultCell(new Dropdown(), $field)['type']);
    }

    /**
     * The one node a strategy declares for its default cell.
     *
     * @return array<string, mixed>
     */
    protected function defaultCell(Dropdown $strategy, CraftFieldInterface $field): array
    {
        return $strategy->schema($field)->toArray()['default'][0] ?? [];
    }

    /**
     * Which flavours actually hold a list — an assertion about CRAFT, not about
     * the strategy, read off the class rather than an instance (constructing a
     * real field wants a database). If one of these ever flips, the arity the
     * strategy reports flips with it and this fails at the source.
     *
     * @dataProvider arities
     * @param class-string $craftFieldClass
     */
    public function testCraftsOwnAritiesAreWhatTheStrategyReadsFrom(string $craftFieldClass, bool $multi): void
    {
        $property = (new ReflectionClass($craftFieldClass))->getProperty('multi');

        $this->assertSame($multi, $property->getValue());
    }

    /** @return iterable<string, array{0: class-string, 1: bool}> */
    public static function arities(): iterable
    {
        yield 'checkboxes hold a list' => [Checkboxes::class, true];
        yield 'multiselect holds a list' => [MultiSelect::class, true];
        yield 'dropdown holds one' => [CraftDropdownField::class, false];
        yield 'radiobuttons hold one' => [RadioButtons::class, false];
        yield 'buttongroup holds one' => [ButtonGroup::class, false];
    }

    /**
     * The strategy as it reads a multi-value flavour. Stubbed because a mock's
     * `getIsMultiOptionsField()` answers false whatever class it mocks, and a real
     * one can't be built without a database — hence the seam.
     */
    private function multiStrategy(): Dropdown
    {
        return new class() extends Dropdown {
            protected function isMulti(CraftFieldInterface $field): bool
            {
                return true;
            }
        };
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
