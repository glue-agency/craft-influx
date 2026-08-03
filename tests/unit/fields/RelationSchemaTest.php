<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\PlainText;
use craft\fields\Tags as CraftTagsField;
use craft\fields\Users as CraftUsersField;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Users;

/**
 * What a relation mapping row offers: the "Match by" options and the ONE
 * sub-field card, per flavour.
 *
 * Both were wrong before. Match-by was the same hardcoded id/slug/title trio for
 * every element type, and the card's rows were probed for by looking up a native
 * field-layout ELEMENT — which Craft ships for an entry's title and for nothing
 * else, so Categories, Tags and Users got no card at all and no flavour ever
 * offered a slug. The related element's own custom fields were never offered
 * either, though the runtime has always applied them.
 *
 * The source-layout walk needs a booted Craft, so it's stubbed per flavour.
 */
class RelationSchemaTest extends Unit
{
    public function testAnEntryOffersItsIdentifiersAndItsOwnFields(): void
    {
        $nodes = $this->entriesSchema([$this->fakeLayout(['campus'])]);

        $this->assertSame(
            ['id', 'title', 'slug', 'uri'],
            array_column($this->matchGroups($nodes)[0]['options'], 'value'),
        );
        $this->assertSame(['title', 'slug', 'campus'], array_column($this->card($nodes)['subFields'], 'handle'));
    }

    public function testAUserOffersUsernameAndEmailRatherThanTitleAndSlug(): void
    {
        // The complaint: a Users relation asked to match on a title no user has
        // and hid the two keys that identify one.
        $nodes = $this->usersSchema([$this->fakeLayout([])]);

        $values = array_column($this->matchGroups($nodes)[0]['options'], 'value');
        $this->assertSame(['id', 'username', 'email'], $values);

        $this->assertSame(
            ['username', 'email', 'fullName', 'firstName', 'lastName'],
            array_column($this->card($nodes)['subFields'], 'handle'),
        );
    }

    public function testACategoryAndATagBothGetACardAtAll(): void
    {
        // Neither used to: the layout-element probe found no title element for
        // either, so the card was gated away entirely.
        $category = $this->categoriesSchema([$this->fakeLayout([])]);
        $this->assertSame(['title', 'slug'], array_column($this->card($category)['subFields'], 'handle'));

        $tag = $this->tagsSchema([$this->fakeLayout([])]);
        $this->assertSame(['title'], array_column($this->card($tag)['subFields'], 'handle'));
    }

    public function testTheRelatedElementsCustomFieldsAreOfferedAsRows(): void
    {
        // Belkin's "Sub-fields doesn't have all the options": the runtime has
        // always applied the `fields` channel, but nothing offered rows for it.
        $nodes = $this->categoriesSchema([
            $this->fakeLayout(['blurb']),
            $this->fakeLayout(['blurb', 'colour']),
        ]);

        $rows = $this->card($nodes)['subFields'];
        $this->assertSame(['title', 'slug', 'blurb', 'colour'], array_column($rows, 'handle'));
        // Deduped across sources, first layout naming it.
        $this->assertSame('Blurb', $rows[2]['label']);
    }

    public function testOnlyTheCustomRowsCarryAChannel(): void
    {
        $rows = $this->card($this->tagsSchema([$this->fakeLayout(['colour'])]))['subFields'];

        $this->assertSame([null, 'fields'], array_map(static fn(array $row): ?string => $row['channel'] ?? null, $rows));
    }

    public function testOneCardHoldsBothChannels(): void
    {
        $nodes = $this->usersSchema([$this->fakeLayout(['importId'])]);

        $cards = array_values(array_filter(
            $nodes,
            static fn(array $node): bool => in_array($node['type'], ['elementSubFields', 'subFields'], true),
        ));

        $this->assertCount(1, $cards);
        $this->assertSame('elementSubFields', $cards[0]['type']);
        $this->assertSame('nativeFields', $cards[0]['handle']);
    }

    public function testANativeWinsAHandleCollision(): void
    {
        // `email` isn't a reserved field handle, so a User layout may carry one.
        // One handle-keyed table can hold one row: the native's.
        $rows = $this->card($this->usersSchema([$this->fakeLayout(['email'])]))['subFields'];

        $this->assertSame(['username', 'email', 'fullName', 'firstName', 'lastName'], array_column($rows, 'handle'));
        $this->assertArrayNotHasKey('channel', $rows[1]);
    }

    public function testACustomFieldIsNotShadowedByAnAttributeItsFlavourLacks(): void
    {
        // The match-by dedupe used to be seeded with a hardcoded id/slug/title,
        // so a Users layout field handled `title` was swallowed by an option
        // that flavour doesn't even offer.
        $nodes = $this->usersSchema([$this->fakeLayout(['title'])]);
        $groups = $this->matchGroups($nodes);

        $this->assertSame('title', $groups[1]['options'][0]['value']);
    }

    /**
     * The schema nodes a flavour's mapping row is built from. Each stub replaces
     * only what needs a booted Craft — the source-layout walk, and for Entries
     * the section lookup its entry-type gating reads.
     *
     * @param list<FieldLayout> $layouts
     * @return list<array>
     */
    protected function entriesSchema(array $layouts): array
    {
        $strategy = new class($layouts) extends Entries {
            /** @var list<FieldLayout> */
            public array $layouts = [];

            public function __construct(array $layouts)
            {
                $this->layouts = $layouts;
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return $this->layouts;
            }

            /** No sections to read, so nothing gates title or slug. */
            protected function sourceEntryTypes(BaseRelationField $field): array
            {
                return [];
            }
        };

        return $strategy->schema($this->createMock(CraftEntriesField::class))->toArray();
    }

    /** @param list<FieldLayout> $layouts @return list<array> */
    protected function usersSchema(array $layouts): array
    {
        $strategy = new class($layouts) extends Users {
            /** @var list<FieldLayout> */
            public array $layouts = [];

            public function __construct(array $layouts)
            {
                $this->layouts = $layouts;
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return $this->layouts;
            }
        };

        return $strategy->schema($this->createMock(CraftUsersField::class))->toArray();
    }

    /** @param list<FieldLayout> $layouts @return list<array> */
    protected function categoriesSchema(array $layouts): array
    {
        $strategy = new class($layouts) extends Categories {
            /** @var list<FieldLayout> */
            public array $layouts = [];

            public function __construct(array $layouts)
            {
                $this->layouts = $layouts;
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return $this->layouts;
            }
        };

        return $strategy->schema($this->createMock(CraftCategoriesField::class))->toArray();
    }

    /** @param list<FieldLayout> $layouts @return list<array> */
    protected function tagsSchema(array $layouts): array
    {
        $strategy = new class($layouts) extends Tags {
            /** @var list<FieldLayout> */
            public array $layouts = [];

            public function __construct(array $layouts)
            {
                $this->layouts = $layouts;
            }

            protected function sourceFieldLayouts(BaseRelationField $field): iterable
            {
                return $this->layouts;
            }
        };

        return $strategy->schema($this->createMock(CraftTagsField::class))->toArray();
    }

    /**
     * @param list<array> $nodes
     * @return list<array>
     */
    protected function matchGroups(array $nodes): array
    {
        foreach ($nodes as $node) {
            if (($node['handle'] ?? null) === 'match') {
                return $node['options'];
            }
        }

        $this->fail('The row offers no Match by control.');
    }

    /**
     * @param list<array> $nodes
     * @return array
     */
    protected function card(array $nodes): array
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'elementSubFields') {
                return $node;
            }
        }

        $this->fail('The row offers no sub-field card.');
    }

    /**
     * A source's fake field layout: one custom field per handle.
     *
     * @param list<string> $handles
     */
    protected function fakeLayout(array $handles): FieldLayout
    {
        $customFields = array_map(function(string $handle): CraftFieldInterface {
            $field = $this->createMock(PlainText::class);
            $field->handle = $handle;
            $field->name = ucfirst($handle);

            return $field;
        }, $handles);

        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getCustomFields')->willReturn($customFields);

        return $layout;
    }
}
