<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\PlainText;
use GlueAgency\Influx\services\LinkBuilderService;

/**
 * How the Mapping tab's default-value element picker is shaped for the field it
 * picks FOR ({@see LinkBuilderService::elementSelectConfigFor()}).
 *
 * The rule: a default has to be something the field could actually hold. So the
 * picker offers exactly the field's own sources and honours its relation limit —
 * one element for a `maxRelations: 1` field, a list for anything higher (or
 * unlimited). Anything that isn't a relation field keeps the historical shape.
 */
class ElementPickerConfigTest extends Unit
{
    public function testAMultiSourceFieldOffersItsOwnSources(): void
    {
        $field = $this->createMock(CraftEntriesField::class);
        $field->sources = ['section:aaa', 'section:bbb'];

        $this->assertSame(['section:aaa', 'section:bbb'], $this->config($field)['sources']);
    }

    public function testASingleSourceFieldWrapsItsOneSource(): void
    {
        // Categories / Tags declare `allowMultipleSources = false` and keep their
        // one source in `source`, leaving `sources` at its '*' default — which
        // must not win, or the picker would offer every group.
        $field = $this->createMock(CraftCategoriesField::class);
        $field->source = 'group:aaa';

        $this->assertSame(['group:aaa'], $this->config($field)['sources']);
    }

    public function testAFieldWithoutConfiguredSourcesOffersEverything(): void
    {
        $field = $this->createMock(CraftEntriesField::class);
        $field->sources = null;

        $this->assertSame('*', $this->config($field)['sources']);
    }

    public function testASingleRelationFieldPicksOneElement(): void
    {
        $field = $this->createMock(CraftEntriesField::class);
        $field->maxRelations = 1;

        $this->assertSame(['limit' => 1, 'single' => true], $this->limits($field));
    }

    public function testACappedMultiRelationFieldPicksUpToItsMax(): void
    {
        $field = $this->createMock(CraftEntriesField::class);
        $field->maxRelations = 3;

        $this->assertSame(['limit' => 3, 'single' => false], $this->limits($field));
    }

    public function testAnUncappedRelationFieldPicksAnyNumber(): void
    {
        // null limit is Craft's own "unlimited" — both the elementSelect partial
        // and BaseElementSelectInput read it that way.
        $field = $this->createMock(CraftEntriesField::class);
        $field->maxRelations = null;

        $this->assertSame(['limit' => null, 'single' => false], $this->limits($field));
    }

    public function testANonRelationFieldKeepsTheHistoricalShape(): void
    {
        $this->assertSame(
            ['sources' => '*', 'limit' => 1, 'single' => true],
            $this->config($this->createMock(PlainText::class)),
        );
    }

    public function testNoFieldAtAllKeepsTheHistoricalShape(): void
    {
        // What the native author row renders with: it sends no handle, precisely
        // so a custom field handled 'author' can't reshape it.
        $this->assertSame(
            ['sources' => '*', 'limit' => 1, 'single' => true],
            $this->config(null),
        );
    }

    protected function config(mixed $field): array
    {
        return (new LinkBuilderService())->elementSelectConfigFor($field);
    }

    /**
     * @return array{limit: int|null, single: bool}
     */
    protected function limits(mixed $field): array
    {
        $config = $this->config($field);

        return ['limit' => $config['limit'], 'single' => $config['single']];
    }
}
