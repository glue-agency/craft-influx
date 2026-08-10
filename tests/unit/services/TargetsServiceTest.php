<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use GlueAgency\Influx\integrations\solspace\calendar\EventTarget;
use GlueAgency\Influx\services\TargetsService;
use GlueAgency\Influx\targets\AssetTarget;
use GlueAgency\Influx\targets\CategoryTarget;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\targets\GlobalSetTarget;
use GlueAgency\Influx\targets\TagTarget;
use GlueAgency\Influx\targets\UserTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Conformance spec for the targets registry: built-ins keyed by element-type
 * FQCN, the availability gate that keeps a target for an uninstalled plugin out
 * of the set, link resolution tolerant of a leading backslash in the stored
 * type, and the friendly-name fallback for an element type nothing is registered
 * for.
 */
class TargetsServiceTest extends Unit
{
    /**
     * A built-in whose element type isn't installed never reaches the registry —
     * the gate that lets Influx ship a target for a third-party element type. The
     * builder ITERATES these to offer one row per element type, asking each for a
     * criteria dropdown built from its own plugin's services, so an ungated one
     * wouldn't sit unused: it would break the builder for every element type.
     *
     * Solspace Calendar isn't a dependency of this repo, so its target is the live
     * example — and it stays inert even where a spec has loaded stub classes for
     * it, since {@see EventTarget::isAvailable()} also requires the PLUGIN.
     */
    public function testAnUninstalledElementTypesTargetIsNotRegistered(): void
    {
        $this->assertFalse(EventTarget::isAvailable());
        $this->assertArrayNotHasKey('Solspace\Calendar\Elements\Event', (new TargetsService())->all());
    }

    /**
     * One target per native Craft element type. Pinned as a set rather than as a
     * count, so adding one is a deliberate edit here.
     */
    public function testRegistersTheBuiltInsKeyedByElementType(): void
    {
        $service = new TargetsService();

        $all = $service->all();

        $expected = [
            Asset::class     => AssetTarget::class,
            Category::class  => CategoryTarget::class,
            Entry::class     => EntryTarget::class,
            GlobalSet::class => GlobalSetTarget::class,
            Tag::class       => TagTarget::class,
            User::class      => UserTarget::class,
        ];

        $this->assertSame(array_keys($expected), array_keys($all));

        foreach ($expected as $elementType => $targetClass) {
            $this->assertInstanceOf($targetClass, $all[$elementType]);
        }
    }

    public function testForLinkResolvesTheStoredElementType(): void
    {
        $service = new TargetsService();

        $this->assertInstanceOf(
            EntryTarget::class,
            $service->forLink(FakeLink::make(['elementType' => Entry::class])),
        );
        $this->assertInstanceOf(
            EntryTarget::class,
            $service->forLink(FakeLink::make(['elementType' => '\craft\elements\Entry'])),
        );
        $this->assertNull($service->forLink(FakeLink::make(['elementType' => 'vendor\elements\Widget'])));
    }

    public function testFriendlyNameFallsBackToTheShortClassName(): void
    {
        $service = new TargetsService();

        $this->assertSame(EntryTarget::friendlyName(), $service->friendlyNameFor(Entry::class));
        $this->assertSame('Widget', $service->friendlyNameFor('vendor\elements\Widget'));
        $this->assertSame('Widget', $service->friendlyNameFor('\vendor\elements\Widget'));
    }
}
