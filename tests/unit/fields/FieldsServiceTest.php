<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\fields\Assets as CraftAssetsField;
use craft\fields\BaseOptionsField;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Checkboxes;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\Lightswitch as CraftLightswitchField;
use craft\fields\MultiSelect;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Tags as CraftTagsField;
use craft\fields\Users as CraftUsersField;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Users;
use GlueAgency\Influx\services\FieldsService;

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

        $this->assertArrayHasKey(CraftAssetsField::class, $byFqcn);
        $this->assertArrayHasKey(CraftLightswitchField::class, $byFqcn);
        $this->assertArrayHasKey(BaseOptionsField::class, $byFqcn);
        $this->assertArrayHasKey(CraftEntriesField::class, $byFqcn);
        $this->assertArrayHasKey(CraftUsersField::class, $byFqcn);
        $this->assertArrayHasKey(CraftCategoriesField::class, $byFqcn);
        $this->assertArrayHasKey(CraftTagsField::class, $byFqcn);

        $this->assertInstanceOf(Assets::class,      $byFqcn[CraftAssetsField::class]);
        $this->assertInstanceOf(Lightswitch::class, $byFqcn[CraftLightswitchField::class]);
        $this->assertInstanceOf(Dropdown::class,    $byFqcn[BaseOptionsField::class]);
        $this->assertInstanceOf(Entries::class,     $byFqcn[CraftEntriesField::class]);
        $this->assertInstanceOf(Users::class,       $byFqcn[CraftUsersField::class]);
        $this->assertInstanceOf(Categories::class,  $byFqcn[CraftCategoriesField::class]);
        $this->assertInstanceOf(Tags::class,        $byFqcn[CraftTagsField::class]);
    }

    public function testParentChainResolutionDispatchesDropdownVariants(): void
    {
        $service = new FieldsService();
        $service->init();

        // craft\fields\RadioButtons extends BaseOptionsField — we shouldn't
        // need a separate strategy for each option-based subclass.
        $field = $this->createMock(RadioButtons::class);
        $this->assertInstanceOf(
            Dropdown::class,
            $service->forCraftField($field),
            'RadioButtons should resolve through BaseOptionsField to the Dropdown strategy.',
        );

        $field = $this->createMock(Checkboxes::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));

        $field = $this->createMock(MultiSelect::class);
        $this->assertInstanceOf(Dropdown::class, $service->forCraftField($field));
    }

    public function testFallsBackToDefaultField(): void
    {
        $service = new FieldsService();
        $service->init();

        $field = $this->createMock(PlainText::class);
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

        $field = $this->createMock(CraftLightswitchField::class);
        $this->assertInstanceOf(LightswitchOverride::class, $service->forCraftField($field));
    }
}

/** @internal Inline override fixture used by the registry test above. */
class LightswitchOverride extends Lightswitch
{
    public static function craftFieldClass(): ?string
    {
        return CraftLightswitchField::class;
    }
}
