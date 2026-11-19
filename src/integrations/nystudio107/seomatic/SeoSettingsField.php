<?php

namespace GlueAgency\Influx\integrations\nystudio107\seomatic;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;
use Throwable;

/**
 * Mapping strategy for SEOmatic's SEO Settings field.
 *
 * The field holds a whole meta bundle, so the row is node-less with one
 * sub-mapping per setting a feed can sensibly carry: the text settings and
 * custom image URLs of the General, Twitter and Facebook tabs, robots and the
 * canonical URL — the list SEOmatic's own Feed Me integration offers, minus
 * the asset pickers.
 *
 * Only the mapped settings change. The rest of the bundle — sitemap settings,
 * an editor's other overrides — is read off the element and handed back as it
 * was, through the same `toArray()` round trip SEOmatic's `normalizeValue()`
 * uses for a bundle. Feed Me's integration replaces the whole bundle instead,
 * resetting everything it doesn't map to the field's defaults.
 *
 * A mapped text setting also gets its source set to Custom, and an image URL
 * its source to Custom URL: that is what an editor picks before typing a value
 * in the CP, and with any other source SEOmatic replaces the value on
 * normalize, so the write would be discarded and read as a change every run.
 *
 * Keyed by class string, so an install without the plugin never touches it.
 */
class SeoSettingsField extends Field
{
    /** The settings whose source is `fromCustom` once a feed writes them. */
    protected const TEXT_SETTINGS = [
        'seoTitle',
        'seoDescription',
        'seoKeywords',
        'seoImageDescription',
        'twitterTitle',
        'twitterDescription',
        'twitterImageDescription',
        'ogTitle',
        'ogDescription',
        'ogImageDescription',
    ];

    /** The settings whose source is `fromUrl` once a feed writes them. */
    protected const IMAGE_URL_SETTINGS = ['seoImage', 'twitterImage', 'ogImage'];

    /** The settings without a source. */
    protected const PLAIN_SETTINGS = ['robots', 'canonicalUrl'];

    public static function craftFieldClass(): ?string
    {
        return 'nystudio107\seomatic\fields\SeoSettings';
    }

    public static function matchable(): bool
    {
        return false;
    }

    /**
     * The robots values SEOmatic's own selects offer.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function robotsOptions(): array
    {
        return [
            ['value' => 'all', 'label' => 'all'],
            ['value' => 'none', 'label' => 'none'],
            ['value' => 'noindex', 'label' => 'noindex'],
            ['value' => 'nofollow', 'label' => 'nofollow'],
            ['value' => 'nosnippet', 'label' => 'nosnippet'],
            ['value' => 'noarchive', 'label' => 'noarchive'],
            ['value' => 'noimageindex', 'label' => 'noimageindex'],
        ];
    }

    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source'  => false,
            'default' => false,
            'extra'   => function(MappingSchemaBuilder $b) {
                $subFields = MappingSchemaBuilder::make();

                foreach ($this->settingLabels() as $handle => $label) {
                    if ($handle === 'robots') {
                        $subFields->select([
                            'handle'  => $handle,
                            'label'   => $label,
                            'options' => static::robotsOptions(),
                        ]);

                        continue;
                    }

                    $subFields->text(['handle' => $handle, 'label' => $label]);
                }

                return $b->subFields([
                    'label'     => Craft::t('influx', 'SEO settings'),
                    'subFields' => $subFields->toArray(),
                ]);
            },
        ]);
    }

    public function addressed(FieldContext $context): bool
    {
        foreach ($this->activeSubMappings($context->mapping) as $sub) {
            if ($sub->addressedBy($context->item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element's current bundle as an array, with the mapped settings and
     * their sources laid over it.
     *
     * @return array<string, mixed>
     */
    public function parse(FieldContext $context): mixed
    {
        $config = $this->bundleArray($this->currentValue($context));
        $config['metaGlobalVars'] = (array) ($config['metaGlobalVars'] ?? []);
        $config['metaBundleSettings'] = (array) ($config['metaBundleSettings'] ?? []);

        foreach ($this->mappedSettings($context->mapping) as $handle => $sub) {
            $value = $sub->resolve($context->item);
            $config['metaGlobalVars'][$handle] = is_scalar($value) ? trim((string) $value) : '';

            if ($source = static::sourceFor($handle)) {
                $config['metaBundleSettings'][$handle . 'Source'] = $source;
            }
        }

        return $config;
    }

    /**
     * The source a written setting needs, or null for one that has none.
     */
    public static function sourceFor(string $handle): ?string
    {
        if (in_array($handle, self::TEXT_SETTINGS, true)) {
            return 'fromCustom';
        }

        if (in_array($handle, self::IMAGE_URL_SETTINGS, true)) {
            return 'fromUrl';
        }

        return null;
    }

    /**
     * Only the mapped settings and their sources are compared: the rest of the
     * bundle is carried over untouched, and comparing it would read SEOmatic's
     * own normalisation of it as a change on every run.
     */
    public function hasChanged(FieldContext $context, mixed $incoming): bool
    {
        try {
            $current = $this->bundleArray($this->currentValue($context));
        } catch (Throwable) {
            return true;
        }

        $incoming = $this->bundleArray($incoming);

        foreach (array_keys($this->mappedSettings($context->mapping)) as $handle) {
            if ((string) ($current['metaGlobalVars'][$handle] ?? '') !== (string) ($incoming['metaGlobalVars'][$handle] ?? '')) {
                return true;
            }

            $source = $handle . 'Source';

            if (static::sourceFor($handle) && ($current['metaBundleSettings'][$source] ?? null) !== ($incoming['metaBundleSettings'][$source] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> handle => label, in the order the CP shows them
     */
    protected function settingLabels(): array
    {
        return [
            'seoTitle'                => Craft::t('influx', 'SEO title'),
            'seoDescription'          => Craft::t('influx', 'SEO description'),
            'seoKeywords'             => Craft::t('influx', 'SEO keywords'),
            'seoImage'                => Craft::t('influx', 'SEO image URL'),
            'seoImageDescription'     => Craft::t('influx', 'SEO image description'),
            'robots'                  => Craft::t('influx', 'Robots'),
            'canonicalUrl'            => Craft::t('influx', 'Canonical URL'),
            'twitterTitle'            => Craft::t('influx', 'X (Twitter) title'),
            'twitterDescription'      => Craft::t('influx', 'X (Twitter) description'),
            'twitterImage'            => Craft::t('influx', 'X (Twitter) image URL'),
            'twitterImageDescription' => Craft::t('influx', 'X (Twitter) image description'),
            'ogTitle'                 => Craft::t('influx', 'Facebook title'),
            'ogDescription'           => Craft::t('influx', 'Facebook description'),
            'ogImage'                 => Craft::t('influx', 'Facebook image URL'),
            'ogImageDescription'      => Craft::t('influx', 'Facebook image description'),
        ];
    }

    /**
     * The active sub-mappings this strategy writes, keyed by setting.
     *
     * @return array<string, FieldMapping>
     */
    protected function mappedSettings(FieldMapping $mapping): array
    {
        $known = array_merge(self::TEXT_SETTINGS, self::IMAGE_URL_SETTINGS, self::PLAIN_SETTINGS);
        $settings = [];

        foreach ($this->activeSubMappings($mapping) as $sub) {
            if (in_array($sub->handle, $known, true)) {
                $settings[$sub->handle] = $sub;
            }
        }

        return $settings;
    }

    protected function currentValue(FieldContext $context): mixed
    {
        return $context->element->getFieldValue($context->handle);
    }

    /**
     * A bundle as the array SEOmatic's `normalizeValue()` takes: a MetaBundle
     * through its own `toArray()`, an array as it is.
     *
     * @return array<string, mixed>
     */
    protected function bundleArray(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return [];
        }

        foreach (['metaGlobalVars', 'metaBundleSettings'] as $part) {
            if (is_object($value[$part] ?? null) && method_exists($value[$part], 'toArray')) {
                $value[$part] = $value[$part]->toArray();
            }
        }

        return $value;
    }

    /**
     * @return list<FieldMapping>
     */
    protected function activeSubMappings(FieldMapping $mapping): array
    {
        return $this->filterActive($mapping->subMappings());
    }
}
