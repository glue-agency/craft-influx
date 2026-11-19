<?php

namespace GlueAgency\Influx\Tests\unit\integrations\presseddigital\linkit;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\integrations\presseddigital\linkit\LinkitField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Linkit strategy: a node-less row whose slots build the
 * array Linkit's `normalizeValue()` takes, with Feed Me's short type names and
 * an HTML `target` accepted alongside Linkit's own spellings.
 */
class LinkitFieldTest extends Unit
{
    public function testCraftFieldClassIsLinkits(): void
    {
        $this->assertSame('presseddigital\linkit\fields\LinkitField', LinkitField::craftFieldClass());
        $this->assertFalse(LinkitField::matchable());
    }

    public function testTheRowHasNeitherCellOfItsOwn(): void
    {
        $schema = (new LinkitWithStubbedSettings())->schema($this->createMock(CraftFieldInterface::class));

        $this->assertFalse($schema->has('source'));
        $this->assertFalse($schema->has('default'));
        $this->assertTrue($schema->has('extra'));
    }

    public function testBuildsTheValueLinkitTakes(): void
    {
        $parsed = (new LinkitWithStubbedSettings())->parse($this->context(
            feed: ['url' => ' https://example.test ', 'text' => 'Read more', 'blank' => true],
            fields: [
                'value'      => ['node' => 'url'],
                'customText' => ['node' => 'text'],
                'target'     => ['node' => 'blank'],
            ],
        ));

        $this->assertSame([
            'type'       => 'presseddigital\linkit\models\Url',
            'value'      => 'https://example.test',
            'customText' => 'Read more',
            'target'     => true,
        ], $parsed);
    }

    public function testAShortTypeNameResolvesToLinkitsModel(): void
    {
        $parsed = (new LinkitWithStubbedSettings())->parse($this->context(
            feed: ['kind' => ' EMAIL ', 'ref' => 'a@example.test'],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));

        $this->assertSame('presseddigital\linkit\models\Email', $parsed['type']);
    }

    public function testAFullClassNameIsAccepted(): void
    {
        $parsed = (new LinkitWithStubbedSettings())->parse($this->context(
            feed: ['kind' => '\presseddigital\linkit\models\Entry', 'ref' => 12],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));

        $this->assertSame(['type' => 'presseddigital\linkit\models\Entry', 'value' => '12'], $parsed);
    }

    /**
     * Linkit instantiates whatever class it is handed, so a type the field doesn't
     * enable must fail this row before it gets there.
     */
    public function testATypeTheFieldDoesNotEnableThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new LinkitWithStubbedSettings())->parse($this->context(
            feed: ['kind' => 'phone', 'ref' => '+32 9 000 00 00'],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));
    }

    public function testNoValueClearsTheField(): void
    {
        $parsed = (new LinkitWithStubbedSettings())->parse($this->context(
            feed: ['url' => '', 'text' => 'Orphaned'],
            fields: [
                'value'      => ['node' => 'url'],
                'customText' => ['node' => 'text'],
            ],
        ));

        $this->assertNull($parsed);
    }

    public function testSlotsTheFieldDoesNotAllowAreSkipped(): void
    {
        $parsed = (new LinkitWithStubbedSettings(customText: false, target: false))->parse($this->context(
            feed: ['url' => 'https://example.test', 'text' => 'Read more', 'blank' => '1'],
            fields: [
                'value'      => ['node' => 'url'],
                'customText' => ['node' => 'text'],
                'target'     => ['node' => 'blank'],
            ],
        ));

        $this->assertSame(['type' => 'presseddigital\linkit\models\Url', 'value' => 'https://example.test'], $parsed);
    }

    public function testAnHtmlTargetReadsAsWhatItMeans(): void
    {
        $this->assertTrue(LinkitField::coerceTarget('_blank'));
        $this->assertFalse(LinkitField::coerceTarget('_self'));
        $this->assertTrue(LinkitField::coerceTarget('yes'));
        $this->assertFalse(LinkitField::coerceTarget(''));
        $this->assertFalse(LinkitField::coerceTarget(null));
    }

    public function testAnUnchangedLinkIsNoChange(): void
    {
        $strategy = new LinkitWithStubbedSettings();
        $stored = new StoredLinkitLink('\presseddigital\linkit\models\Url', 'https://example.test', null, false);
        $incoming = ['type' => 'presseddigital\linkit\models\Url', 'value' => 'https://example.test', 'customText' => '', 'target' => false];

        $this->assertFalse($strategy->hasChanged($this->context([], [], current: $stored), $incoming));
    }

    public function testADifferentTargetIsAChange(): void
    {
        $strategy = new LinkitWithStubbedSettings();
        $stored = new StoredLinkitLink('presseddigital\linkit\models\Url', 'https://example.test', null, false);
        $incoming = ['type' => 'presseddigital\linkit\models\Url', 'value' => 'https://example.test', 'target' => true];

        $this->assertTrue($strategy->hasChanged($this->context([], [], current: $stored), $incoming));
    }

    public function testClearingAPopulatedLinkIsAChange(): void
    {
        $strategy = new LinkitWithStubbedSettings();
        $stored = new StoredLinkitLink('presseddigital\linkit\models\Url', 'https://example.test', null, null);

        $this->assertTrue($strategy->hasChanged($this->context([], [], current: $stored), null));
        $this->assertFalse($strategy->hasChanged($this->context([], [], current: null), null));
    }

    private function context(array $feed, array $fields, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'cta',
            mapping: FieldMapping::fromConfig('cta', ['fields' => $fields]),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}

/**
 * @internal The real accessors read a Linkit field's own settings; what this
 * spec is about is what the strategy does with them.
 */
class LinkitWithStubbedSettings extends LinkitField
{
    protected bool $customText = true;

    protected bool $target = true;

    public function __construct(bool $customText = true, bool $target = true)
    {
        $this->customText = $customText;
        $this->target = $target;
    }

    protected function enabledTypes(?CraftFieldInterface $field): array
    {
        return [
            'presseddigital\linkit\models\Url'   => 'URL',
            'presseddigital\linkit\models\Email' => 'Email',
            'presseddigital\linkit\models\Entry' => 'Entry',
        ];
    }

    protected function allowsCustomText(?CraftFieldInterface $field): bool
    {
        return $this->customText;
    }

    protected function allowsTarget(?CraftFieldInterface $field): bool
    {
        return $this->target;
    }
}

/**
 * @internal What a Linkit link model exposes to the comparison.
 */
class StoredLinkitLink
{
    public ?string $type = null;

    public ?string $value = null;

    public ?string $customText = null;

    public ?bool $target = null;

    public function __construct(?string $type, ?string $value, ?string $customText, ?bool $target)
    {
        $this->type = $type;
        $this->value = $value;
        $this->customText = $customText;
        $this->target = $target;
    }
}
