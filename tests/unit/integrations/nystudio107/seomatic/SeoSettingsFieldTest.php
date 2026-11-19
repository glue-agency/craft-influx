<?php

namespace GlueAgency\Influx\Tests\unit\integrations\nystudio107\seomatic;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\integrations\nystudio107\seomatic\SeoSettingsField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for the SEO Settings strategy: the mapped settings are laid
 * over the element's current bundle, a written text setting or image URL gets
 * the source that keeps it, and only the mapped settings count as a change.
 */
class SeoSettingsFieldTest extends Unit
{
    public function testCraftFieldClassIsSeomatics(): void
    {
        $this->assertSame('nystudio107\seomatic\fields\SeoSettings', SeoSettingsField::craftFieldClass());
        $this->assertFalse(SeoSettingsField::matchable());
    }

    public function testTheRowHasNeitherCellOfItsOwn(): void
    {
        $schema = (new SeoSettingsField())->schema($this->createMock(CraftFieldInterface::class));

        $this->assertFalse($schema->has('source'));
        $this->assertFalse($schema->has('default'));
        $this->assertTrue($schema->has('extra'));
    }

    public function testMappedSettingsAreLaidOverTheCurrentBundle(): void
    {
        $current = new StoredBundle(
            ['seoTitle' => 'Old title', 'seoDescription' => 'Kept', 'robots' => ''],
            ['seoTitleSource'  => 'fromField', 'seoDescriptionSource' => 'fromCustom'],
            ['sitemapPriority' => 0.8],
        );

        $parsed = (new SeoSettingsField())->parse($this->context(
            feed: ['meta' => ['title' => ' New title ', 'robots' => 'noindex', 'image' => 'https://example.test/a.jpg']],
            fields: [
                'seoTitle' => ['node' => 'meta.title'],
                'robots'   => ['node' => 'meta.robots'],
                'seoImage' => ['node' => 'meta.image'],
            ],
            current: $current,
        ));

        $this->assertSame([
            'seoTitle'       => 'New title',
            'seoDescription' => 'Kept',
            'robots'         => 'noindex',
            'seoImage'       => 'https://example.test/a.jpg',
        ], $parsed['metaGlobalVars']);
        $this->assertSame([
            'seoTitleSource'       => 'fromCustom',
            'seoDescriptionSource' => 'fromCustom',
            'seoImageSource'       => 'fromUrl',
        ], $parsed['metaBundleSettings']);
        $this->assertSame(['sitemapPriority' => 0.8], $parsed['metaSitemapVars']);
    }

    public function testAnEmptyFeedValueClearsTheSetting(): void
    {
        $parsed = (new SeoSettingsField())->parse($this->context(
            feed: ['meta' => ['title' => null]],
            fields: ['seoTitle' => ['node' => 'meta.title']],
            current: new StoredBundle(['seoTitle' => 'Old title'], []),
        ));

        $this->assertSame('', $parsed['metaGlobalVars']['seoTitle']);
    }

    public function testAnUnknownSettingIsNotWritten(): void
    {
        $parsed = (new SeoSettingsField())->parse($this->context(
            feed: ['x' => 'y'],
            fields: ['seoImageIds' => ['node' => 'x']],
            current: new StoredBundle([], []),
        ));

        $this->assertArrayNotHasKey('seoImageIds', $parsed['metaGlobalVars']);
    }

    public function testAnUnchangedMappedSettingIsNoChangeWhateverElseDiffers(): void
    {
        $current = new StoredBundle(
            ['seoTitle' => 'Title', 'seoDescription' => 'Editor text'],
            ['seoTitleSource' => 'fromCustom'],
        );
        $incoming = [
            'metaGlobalVars'     => ['seoTitle' => 'Title', 'seoDescription' => 'Something SEOmatic normalised'],
            'metaBundleSettings' => ['seoTitleSource' => 'fromCustom'],
        ];

        $this->assertFalse((new SeoSettingsField())->hasChanged(
            $this->context(feed: [], fields: ['seoTitle' => ['node' => 't']], current: $current),
            $incoming,
        ));
    }

    public function testASourceThatWouldDiscardTheValueIsAChange(): void
    {
        $current = new StoredBundle(['seoTitle' => 'Title'], ['seoTitleSource' => 'fromField']);
        $incoming = [
            'metaGlobalVars'     => ['seoTitle' => 'Title'],
            'metaBundleSettings' => ['seoTitleSource' => 'fromCustom'],
        ];

        $this->assertTrue((new SeoSettingsField())->hasChanged(
            $this->context(feed: [], fields: ['seoTitle' => ['node' => 't']], current: $current),
            $incoming,
        ));
    }

    public function testOnlySettingsWithASourceGetOne(): void
    {
        $this->assertSame('fromCustom', SeoSettingsField::sourceFor('ogDescription'));
        $this->assertSame('fromUrl', SeoSettingsField::sourceFor('twitterImage'));
        $this->assertNull(SeoSettingsField::sourceFor('robots'));
        $this->assertNull(SeoSettingsField::sourceFor('canonicalUrl'));
    }

    private function context(array $feed, array $fields, mixed $current = null): FieldContext
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldValue')->willReturn($current);

        return new FieldContext(
            craftField: null,
            handle: 'seo',
            mapping: FieldMapping::fromConfig('seo', ['fields' => $fields]),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $element,
        );
    }
}

/**
 * @internal What a MetaBundle exposes to the strategy: its own toArray().
 */
class StoredBundle
{
    protected array $globals = [];

    protected array $settings = [];

    protected array $sitemap = [];

    public function __construct(array $globals, array $settings, array $sitemap = [])
    {
        $this->globals = $globals;
        $this->settings = $settings;
        $this->sitemap = $sitemap;
    }

    public function toArray(): array
    {
        return [
            'metaGlobalVars'     => $this->globals,
            'metaSitemapVars'    => $this->sitemap,
            'metaBundleSettings' => $this->settings,
        ];
    }
}
