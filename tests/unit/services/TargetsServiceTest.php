<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\elements\User;
use GlueAgency\Influx\services\TargetsService;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\targets\UserTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Conformance spec for the targets registry: built-ins keyed by element-type
 * FQCN, link resolution tolerant of a leading backslash in the stored type,
 * and the friendly-name fallback for an element type nothing is registered for.
 */
class TargetsServiceTest extends Unit
{
    public function testRegistersTheBuiltInsKeyedByElementType(): void
    {
        $service = new TargetsService();

        $all = $service->all();

        $this->assertSame([Entry::class, User::class], array_keys($all));
        $this->assertInstanceOf(EntryTarget::class, $all[Entry::class]);
        $this->assertInstanceOf(UserTarget::class, $all[User::class]);
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
