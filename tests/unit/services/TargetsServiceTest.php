<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
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
 * FQCN, link resolution tolerant of a leading backslash in the stored type,
 * and the friendly-name fallback for an element type nothing is registered for.
 */
class TargetsServiceTest extends Unit
{
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
