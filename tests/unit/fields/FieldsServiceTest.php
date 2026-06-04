<?php

namespace TDM\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use TDM\Influx\fields\Assets;
use TDM\Influx\fields\Categories;
use TDM\Influx\fields\DefaultField;
use TDM\Influx\fields\Dropdown;
use TDM\Influx\fields\Entries;
use TDM\Influx\fields\Lightswitch;
use TDM\Influx\fields\Relation;
use TDM\Influx\fields\Tags;
use TDM\Influx\fields\Users;
use TDM\Influx\services\FieldsService;

/**
 * Registry behaviour spec.
 *
 *   - Lookup walks parent class chain so concrete Craft Dropdown / Radio /
 *     Checkboxes / MultiSelect all resolve to the BaseOptionsField strategy.
 *   - Unknown Craft field types fall through to DefaultField.
 *   - registerClass(Class) replaces an existing entry for the same FQCN —
 *     this is the hook third parties use to override built-ins.
 */
class FieldsServiceTest extends Unit
{
    public function testBuiltInsAreRegistered(): void
    {
        $service = new FieldsService();
        $service->init();

        $byFqcn = $service->all();

        $this->assertArrayHasKey(\craft\fields\Assets::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\Lightswitch::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\BaseOptionsField::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\Entries::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\Users::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\Categories::class, $byFqcn);
        $this->assertArrayHasKey(\craft\fields\Tags::class, $byFqcn);

        $this->assertInstanceOf(Assets::class,      $byFqcn[\craft\fields\Assets::class]);
        $this->assertInstanceOf(Lightswitch::class, $byFqcn[\craft\fields\Lightswitch::class]);
        $this->assertInstanceOf(Dropdown::class,    $byFqcn[\craft\fields\BaseOptionsField::class]);
        $this->assertInstanceOf(Entries::class,     $byFqcn[\craft\fields\Entries::class]);
        $this->assertInstanceOf(Users::class,       $byFqcn[\craft\fields\Users::class]);
        $this->assertInstanceOf(Categories::class,  $byFqcn[\craft\fields\Categories::class]);
        $this->assertInstanceOf(Tags::class,        $byFqcn[\craft\fields\Tags::class]);
    }

    public function testParentChainResolutionDispatchesDropdownVariants(): void
    {
        $service = new FieldsService();
        $service->init();

        // craft\fields\RadioButtons extends BaseOptionsField — we shouldn't
        // need a separate strategy for each option-based subclass.
        $field = $this->createMock(\craft\fields\RadioButtons::class);
        $this->assertInstanceOf(
            Dropdown::class,
            $service->forCraftField($field),
            'RadioButtons should resolve through BaseOptionsField to the Dropdown strategy.',
        );

        $field = $this->createMock(\craft\fields\Checkboxes::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));

        $field = $this->createMock(\craft\fields\MultiSelect::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));
    }

    public function testFallsBackToDefaultField(): void
    {
        $service = new FieldsService();
        $service->init();

        $field = $this->createMock(\craft\fields\PlainText::class);
        $this->assertInstanceOf(
            DefaultField::class,
            $service->forCraftField($field),
            'PlainText has no dedicated strategy; it must resolve to the DefaultField fallback.',
        );
    }

    public function testRegisterClassReplacesExistingStrategy(): void
    {
        $service = new FieldsService();
        $service->init();

        // Replace the built-in Lightswitch handler.
        $service->registerClass(LightswitchOverride::class);

        $field = $this->createMock(\craft\fields\Lightswitch::class);
        $this->assertInstanceOf(LightswitchOverride::class, $service->forCraftField($field));
    }
}

/** @internal Inline override fixture used by the registry test above. */
class LightswitchOverride extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return \craft\fields\Lightswitch::class;
    }
}
