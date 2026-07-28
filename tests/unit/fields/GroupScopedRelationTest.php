<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\db\Table as CraftTable;
use craft\elements\db\CategoryQuery;
use craft\elements\db\TagQuery;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Tags as CraftTagsField;
use craft\models\FieldLayout;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\Tests\unit\Support\GroupScopedRelationSpy;

/**
 * Behaviour spec for the two group-scoped relation flavours running on the
 * shared {@see \GlueAgency\Influx\fields\GroupScopedRelation} base: source
 * decoding, lookup scoping and the create guard are the base's, while each
 * flavour contributes its source-key prefix, its group table and its group
 * lookup — and Tags its creation default.
 *
 * The two Craft-dependent halves (UID → row id, the element save) are stubbed
 * by {@see GroupScopedRelationSpy}; what's asserted is that the base routes
 * through each subclass's variation points and honours the guards.
 */
class GroupScopedRelationTest extends Unit
{
    public function testEachFlavourDecodesOnlyItsOwnSourcePrefix(): void
    {
        $layout = $this->createMock(FieldLayout::class);

        $categories = new class() extends Categories {
            use GroupScopedRelationSpy;
        };
        $categories->layout = $layout;

        $this->assertSame([$layout], $categories->exposedSourceFieldLayouts($this->field(CraftCategoriesField::class, 'group:abc')));
        $this->assertSame(['abc'], $categories->layoutUids);
        $this->assertSame([], $categories->exposedSourceFieldLayouts($this->field(CraftCategoriesField::class, 'taggroup:abc')));

        $tags = new class() extends Tags {
            use GroupScopedRelationSpy;
        };
        $tags->layout = $layout;

        $this->assertSame([$layout], $tags->exposedSourceFieldLayouts($this->field(CraftTagsField::class, 'taggroup:xyz')));
        $this->assertSame(['xyz'], $tags->layoutUids);
        $this->assertSame([], $tags->exposedSourceFieldLayouts($this->field(CraftTagsField::class, 'group:xyz')));
    }

    public function testLookupsAreScopedToTheFieldsOwnGroup(): void
    {
        $categories = new class() extends Categories {
            use GroupScopedRelationSpy;
        };
        $categories->groupId = 7;

        $categoryQuery = $this->createMock(CategoryQuery::class);
        $categoryQuery->expects($this->once())->method('groupId')->with(7);
        $categories->exposedScopeBySources($this->context(CraftCategoriesField::class, 'group:abc'), $categoryQuery);
        $this->assertSame([['group:abc', 'group:', CraftTable::CATEGORYGROUPS]], $categories->resolved);

        $tags = new class() extends Tags {
            use GroupScopedRelationSpy;
        };
        $tags->groupId = 9;

        $tagQuery = $this->createMock(TagQuery::class);
        $tagQuery->expects($this->once())->method('groupId')->with(9);
        $tags->exposedScopeBySources($this->context(CraftTagsField::class, 'taggroup:xyz'), $tagQuery);
        $this->assertSame([['taggroup:xyz', 'taggroup:', CraftTable::TAGGROUPS]], $tags->resolved);
    }

    public function testAnUnresolvableGroupNeitherScopesNorCreates(): void
    {
        $categories = new class() extends Categories {
            use GroupScopedRelationSpy;
        };
        $categories->groupId = null;

        $query = $this->createMock(CategoryQuery::class);
        $query->expects($this->never())->method('groupId');

        $context = $this->context(CraftCategoriesField::class, 'group:gone');
        $categories->exposedScopeBySources($context, $query);

        $this->assertNull(
            $categories->exposedCreateMissing($context, 'Fiction'),
            'Without a resolvable group there is nowhere to put a new element, so nothing is created.',
        );
    }

    public function testCreationIsOptInForCategoriesAndOnByDefaultForTags(): void
    {
        $categories = new class() extends Categories {
            use GroupScopedRelationSpy;
        };
        $this->assertFalse($categories->exposedShouldCreate($this->context(CraftCategoriesField::class, 'group:abc')));
        $this->assertTrue($categories->exposedShouldCreate($this->context(CraftCategoriesField::class, 'group:abc', ['create' => true])));

        $tags = new class() extends Tags {
            use GroupScopedRelationSpy;
        };
        $this->assertTrue($tags->exposedShouldCreate($this->context(CraftTagsField::class, 'taggroup:xyz')));
        $this->assertFalse($tags->exposedShouldCreate($this->context(CraftTagsField::class, 'taggroup:xyz', ['create' => false])));
    }

    protected function field(string $class, string $source): BaseRelationField
    {
        $field = $this->createMock($class);
        $field->source = $source;

        return $field;
    }

    protected function context(string $fieldClass, string $source, array $options = []): FieldContext
    {
        return new FieldContext(
            craftField: $this->field($fieldClass, $source),
            handle: 'topics',
            mapping: FieldMapping::fromConfig('topics', ['node' => 'topics', 'options' => $options]),
            item: new RemoteItem(['topics' => 'Fiction']),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
