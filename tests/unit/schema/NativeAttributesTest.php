<?php

namespace GlueAgency\Influx\Tests\unit\schema;

use Codeception\Test\Unit;
use craft\models\EntryType;
use GlueAgency\Influx\schema\NativeAttributes;

/**
 * The per-element-type native lists every match-by dropdown and sub-field card
 * is built from. What matters is what each element type does NOT offer: the
 * hardcoded id/slug/title trio these replace is the reason a Users relation
 * offered a title no user has while hiding the username and email that identify
 * one.
 *
 * The suite boots no Craft, so the one config-dependent branch
 * (`useEmailAsUsername`) is exercised through the class's own late-static seam
 * rather than by writing to a general-config object that doesn't exist.
 */
class NativeAttributesTest extends Unit
{
    public function testEveryMatchListLeadsWithTheIdEveryElementHas(): void
    {
        $lists = [
            NativeAttributes::entryMatchable(),
            NativeAttributes::userMatchable(),
            NativeAttributes::categoryMatchable(),
            NativeAttributes::tagMatchable(),
        ];

        foreach ($lists as $list) {
            $this->assertSame('id', $list[0]['value']);
        }
    }

    public function testAUserOffersItsOwnIdentifiersAndNeitherTitleNorSlug(): void
    {
        // User::hasTitles() is false, so elements_sites.title is null for every
        // user — a title match can never resolve one.
        $values = array_column(NativeAttributes::userMatchable(), 'value');

        $this->assertSame(['id', 'username', 'email'], $values);
    }

    public function testUsingTheEmailAsUsernameDropsUsernameFromBothLists(): void
    {
        // Then it's a copy of the email rather than a second key — and Craft
        // itself drops the field from the user layout under that setting.
        $this->assertSame(['id', 'email'], array_column(EmailAsUsername::userMatchable(), 'value'));
        $this->assertNotContains('username', array_column(EmailAsUsername::userWritable(), 'handle'));
    }

    public function testAUsersWritableRowsAreTheProfileStringsOnly(): void
    {
        // No `id` (it identifies, it isn't written) and no `enabled` (a select
        // needing its own coercion, which the native writer doesn't do).
        $handles = array_column(NativeAttributes::userWritable(), 'handle');

        $this->assertSame(['username', 'email', 'fullName'], $handles);
    }

    /**
     * Craft's Full Name layout element renders EITHER one full-name input OR a
     * First/Last pair, on `showFirstAndLastNameFields`, and branches its own
     * validation on the same setting — so offering all three would put a row in
     * the card for a shape the CP never shows.
     */
    public function testOnlyTheNameShapeTheCpRendersIsOffered(): void
    {
        $this->assertSame(
            ['username', 'email', 'firstName', 'lastName'],
            array_column(SplitNames::userWritable(), 'handle'),
        );
    }

    /**
     * The element is removable through `EVENT_DEFINE_NATIVE_FIELDS`; with it gone
     * none of the three is editable anywhere, so none is offered.
     */
    public function testAUserLayoutWithoutTheNameElementOffersNoNames(): void
    {
        $this->assertSame(
            ['username', 'email'],
            array_column(NoNameElement::userWritable(), 'handle'),
        );
    }

    public function testAnEntryOffersUriForMatchingButNeverForWriting(): void
    {
        // Craft derives the uri from the section's URI format on save, so a
        // written one would be overwritten.
        $this->assertContains('uri', array_column(NativeAttributes::entryMatchable(), 'value'));
        $this->assertNotContains('uri', array_column(NativeAttributes::entryWritable(), 'handle'));
    }

    public function testAnEntryTypeThatHidesTitleOrSlugHidesItFromBothLists(): void
    {
        $titleless = $this->entryType(hasTitleField: false, showSlugField: true);

        $this->assertSame(['id', 'slug', 'uri'], array_column(NativeAttributes::entryMatchable([$titleless]), 'value'));
        $this->assertSame(['slug'], array_column(NativeAttributes::entryWritable([$titleless]), 'handle'));

        $slugless = $this->entryType(hasTitleField: true, showSlugField: false);

        $this->assertSame(['id', 'title', 'uri'], array_column(NativeAttributes::entryMatchable([$slugless]), 'value'));
        $this->assertSame(['title'], array_column(NativeAttributes::entryWritable([$slugless]), 'handle'));
    }

    public function testAnAttributeAnyEntryTypeShowsIsOffered(): void
    {
        // A relation field's sources are a union: a row that doesn't apply to
        // one of the types is inert for that type rather than wrong for all.
        $types = [
            $this->entryType(hasTitleField: false, showSlugField: false),
            $this->entryType(hasTitleField: true, showSlugField: true),
        ];

        $this->assertSame(['title', 'slug'], array_column(NativeAttributes::entryWritable($types), 'handle'));
    }

    public function testUnresolvedEntryTypesGateNothing(): void
    {
        // Hiding a row because the lookup failed would read as "Craft doesn't
        // support this", so an empty list offers the lot.
        $this->assertSame(['id', 'title', 'slug', 'uri'], array_column(NativeAttributes::entryMatchable(), 'value'));
        $this->assertSame(['title', 'slug'], array_column(NativeAttributes::entryWritable(), 'handle'));
    }

    public function testAnEntryTitleTakesTheLabelItsLayoutGivesIt(): void
    {
        $options = NativeAttributes::entryMatchable([], 'Kop');

        $this->assertSame('Kop (title)', $options[1]['label']);
        $this->assertSame('Kop', NativeAttributes::entryWritable([], 'Kop')[0]['label']);
    }

    public function testACategoryOffersTitleAndSlugButATagOnlyTitle(): void
    {
        // A tag HAS a slug column, but Craft derives it from the title on save
        // and its own editor never shows one.
        $this->assertSame(['id', 'title', 'slug'], array_column(NativeAttributes::categoryMatchable(), 'value'));
        $this->assertSame(['title', 'slug'], array_column(NativeAttributes::categoryWritable(), 'handle'));

        $this->assertSame(['id', 'title'], array_column(NativeAttributes::tagMatchable(), 'value'));
        $this->assertSame(['title'], array_column(NativeAttributes::tagWritable(), 'handle'));
    }

    public function testMatchLabelsCarryTheHandleTheyMatchOn(): void
    {
        foreach (NativeAttributes::categoryMatchable() as $option) {
            $this->assertStringEndsWith('(' . $option['value'] . ')', $option['label']);
        }
    }

    protected function entryType(bool $hasTitleField, bool $showSlugField): EntryType
    {
        $entryType = new EntryType();
        $entryType->hasTitleField = $hasTitleField;

        if (property_exists($entryType, 'showSlugField')) {
            $entryType->showSlugField = $showSlugField;
        }

        return $entryType;
    }
}

/**
 * The same lists as read on a site that uses the email as the username. A
 * subclass rather than a config write: the suite boots no Craft to configure.
 */
class EmailAsUsername extends NativeAttributes
{
    protected static function usesSeparateUsername(): bool
    {
        return false;
    }
}

/** The same lists on a site showing First/Last Name rather than one Full Name. */
class SplitNames extends NativeAttributes
{
    protected static function userNameWritable(): array
    {
        return [
            ['handle' => 'firstName', 'label' => 'First Name'],
            ['handle' => 'lastName',  'label' => 'Last Name'],
        ];
    }
}

/** A user layout a module stripped the name element out of. */
class NoNameElement extends NativeAttributes
{
    protected static function userLayoutShowsNames(): bool
    {
        return false;
    }
}
