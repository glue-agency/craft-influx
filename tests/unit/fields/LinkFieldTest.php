<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\Link as CraftLinkField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Link;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the Link strategy. On the DefaultField fallback only a bare
 * URL could ever land — Craft reads a plain string as a URL-type link — so the
 * type, label and advanced attributes were unreachable. The row is node-less and
 * assembles Craft's own array envelope from its sub-mappings instead.
 */
class LinkFieldTest extends Unit
{
    public function testCraftFieldClassIsLink(): void
    {
        $this->assertSame(CraftLinkField::class, Link::craftFieldClass());
    }

    /**
     * The row declares neither cell, so the builder renders it as sub-fields only
     * — the same absence Table and ContentBlock declare.
     */
    public function testTheRowHasNeitherCellOfItsOwn(): void
    {
        $schema = (new LinkWithStubbedSettings())->schema($this->createMock(CraftFieldInterface::class));

        $this->assertFalse($schema->has('source'));
        $this->assertFalse($schema->has('default'));
        $this->assertTrue($schema->has('extra'));
    }

    public function testBuildsTheEnvelopeCraftConsumes(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['url' => 'https://example.test', 'text' => 'Read more'],
            fields: [
                'value' => ['node' => 'url'],
                'label' => ['node' => 'text'],
            ],
        ));

        $this->assertSame([
            'type'  => 'url',
            'value' => 'https://example.test',
            'label' => 'Read more',
        ], $parsed);
    }

    public function testTheTypeComesFromTheFeedWhenMapped(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['kind' => 'entry', 'ref' => '1234'],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));

        $this->assertSame(['type' => 'entry', 'value' => '1234'], $parsed);
    }

    public function testTheTypeFallsBackToTheFieldsFirstAllowedType(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['url' => 'mailto:a@example.test'],
            fields: ['value' => ['node' => 'url']],
        ));

        $this->assertSame('url', $parsed['type']);
    }

    public function testATypeSpellingIsMatchedLoosely(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['kind' => ' EMAIL ', 'ref' => 'a@example.test'],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));

        $this->assertSame('email', $parsed['type']);
    }

    /**
     * Craft throws InvalidArgumentException for an unknown type, which would fail
     * the whole item; this fails just the row.
     */
    public function testATypeTheFieldDoesNotAllowThrows(): void
    {
        $this->expectException(MappingValueException::class);

        (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['kind' => 'telepathy', 'ref' => 'x'],
            fields: [
                'type'  => ['node' => 'kind'],
                'value' => ['node' => 'ref'],
            ],
        ));
    }

    public function testTheDownloadFlagIsCoercedLikeAnyOtherBoolean(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['url' => 'https://example.test/f.pdf', 'dl' => 'yes'],
            fields: [
                'value'    => ['node' => 'url'],
                'download' => ['node' => 'dl'],
            ],
        ));

        $this->assertTrue($parsed['download']);
    }

    /**
     * A slot the field has since switched off leaves a stored mapping behind; it
     * is skipped rather than written, the way Table skips a removed column.
     */
    public function testASlotTheFieldNoLongerOffersIsSkipped(): void
    {
        $parsed = (new LinkWithStubbedSettings(labelField: false))->parse($this->context(
            feed: ['url' => 'https://example.test', 'text' => 'Read more'],
            fields: [
                'value' => ['node' => 'url'],
                'label' => ['node' => 'text'],
            ],
        ));

        $this->assertArrayNotHasKey('label', $parsed);
    }

    public function testALinkWithNoValueClearsTheField(): void
    {
        $parsed = (new LinkWithStubbedSettings())->parse($this->context(
            feed: ['text' => 'Read more'],
            fields: [
                'value' => ['node' => 'url'],
                'label' => ['node' => 'text'],
            ],
        ));

        $this->assertNull($parsed, 'A label with nothing to label is not a link.');
    }

    public function testTheRowIsAddressedThroughItsSubMappings(): void
    {
        $strategy = new LinkWithStubbedSettings();

        $this->assertTrue($strategy->addressed($this->context(
            feed: ['url' => 'https://example.test'],
            fields: ['value' => ['node' => 'url']],
        )));

        $this->assertFalse($strategy->addressed($this->context(
            feed: [],
            fields: ['value' => ['node' => 'url']],
        )));
    }

    /**
     * The stored side is a LinkData whose serialize() emits the same envelope
     * parse() builds — which is what lets one reduction cover both.
     */
    public function testUnchangedFeedIsNotAChange(): void
    {
        $strategy = new LinkWithStubbedSettings();
        $incoming = ['type' => 'url', 'value' => 'https://example.test', 'label' => 'Read more'];

        $stored = ['value' => 'https://example.test', 'type' => 'url', 'label' => 'Read more'];
        $this->assertFalse($strategy->hasChanged($this->context([], [], current: $stored), $incoming));
    }

    public function testAnEmptyAdvancedKeyEqualsAnAbsentOne(): void
    {
        $strategy = new LinkWithStubbedSettings();
        $incoming = ['type' => 'url', 'value' => 'https://example.test', 'target' => '', 'download' => false];
        $stored = ['value' => 'https://example.test', 'type' => 'url'];

        $this->assertFalse(
            $strategy->hasChanged($this->context([], [], current: $stored), $incoming),
            "Craft drops empty keys on serialization, so they can't read as a change.",
        );
    }

    public function testADifferentTargetIsAChange(): void
    {
        $strategy = new LinkWithStubbedSettings();
        $incoming = ['type' => 'url', 'value' => 'https://example.test', 'target' => '_blank'];
        $stored = ['value' => 'https://example.test', 'type' => 'url'];

        $this->assertTrue($strategy->hasChanged($this->context([], [], current: $stored), $incoming));
    }

    public function testClearingAPopulatedLinkIsAChange(): void
    {
        $strategy = new LinkWithStubbedSettings();
        $stored = ['value' => 'https://example.test', 'type' => 'url'];

        $this->assertTrue($strategy->hasChanged($this->context([], [], current: $stored), null));
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
 * @internal The real accessors read a craft\fields\Link's own settings; what this
 * spec is about is what the strategy does with them.
 */
class LinkWithStubbedSettings extends Link
{
    protected bool $labelField = true;

    public function __construct(bool $labelField = true)
    {
        $this->labelField = $labelField;
    }

    protected function linkTypeIds(?CraftFieldInterface $field): array
    {
        return ['url', 'entry', 'email'];
    }

    protected function advancedHandles(?CraftFieldInterface $field): array
    {
        return ['target', 'download'];
    }

    protected function showsLabelField(?CraftFieldInterface $field): bool
    {
        return $this->labelField;
    }
}
