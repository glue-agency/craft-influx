<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\elements\Tag;
use craft\models\TagGroup;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\targets\TagTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * TagTarget: the tag group scopes and owns, and the native surface is
 * deliberately narrower than every other element type's — a title and a status,
 * no slug.
 */
class TagTargetTest extends Unit
{
    public function testTheGroupCriterionGatesBothPredicates(): void
    {
        $target = $this->targetWithGroups(['keywords' => 'Keywords', 'themes' => 'Themes']);
        $link = $this->link(['group' => 'keywords']);

        $tag = $this->tag('keywords', null);
        $this->assertTrue($target->targetsElement($link, $tag));
        $this->assertFalse($target->claimsElement($link, $tag));

        $tag = $this->tag('keywords', 'abc');
        $this->assertTrue($target->claimsElement($link, $tag));

        $this->assertFalse($target->targetsElement($link, $this->tag('themes', 'abc')));
    }

    public function testATagWithNoGroupIsNotTargeted(): void
    {
        // This predicate runs on every element edit screen, so an element whose
        // group isn't set has to answer "not mine" rather than throw out of
        // Tag::getGroup().
        $target = $this->targetWithGroups(['keywords' => 'Keywords']);
        $tag = $this->tag('keywords', 'abc');
        $tag->groupId = null;

        $this->assertFalse($target->targetsElement($this->link(['group' => 'keywords']), $tag));
    }

    public function testANonTagIsNeverTargeted(): void
    {
        $notATag = new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };

        $this->assertFalse((new TagTarget())->targetsElement($this->link(['group' => 'keywords']), $notATag));
    }

    public function testAScopedLinkClaimsExactlyItsGroup(): void
    {
        $this->assertSame(['keywords'], (new TagTarget())->claimCells($this->link(['group' => 'keywords'])));
    }

    public function testCriteriaLabelNamesTheGroupAndFallsBackToTheHandle(): void
    {
        $target = $this->targetWithGroups(['keywords' => 'Keywords']);

        $this->assertNull($target->criteriaLabel($this->link([])));
        $this->assertSame('Keywords', $target->criteriaLabel($this->link(['group' => 'keywords'])));
        $this->assertSame('themes', $target->criteriaLabel($this->link(['group' => 'themes'])));
    }

    public function testTheNativeSurfaceIsTitleAndStatusOnly(): void
    {
        // No slug: Craft derives one from the title on save and its own tag editor
        // never shows the field, so a row for it would promise an edit that doesn't
        // stick. Also no parent — tag groups aren't structures.
        $handles = array_map(
            static fn(MappableField $field): string => $field->handle,
            (new TagTarget())->getMappableFields($this->link([])),
        );

        $this->assertSame(['title', 'enabled'], $handles);
    }

    public function testMatchingIsByIdOrTitle(): void
    {
        $values = array_column((new TagTarget())->matchableNativeAttributes($this->link([])), 'value');

        $this->assertSame(['id', 'title'], $values);
    }

    // -- fixtures -------------------------------------------------------------

    protected function link(array $criteria): Link
    {
        return FakeLink::make([
            'elementType'     => Tag::class,
            'elementCriteria' => $criteria,
            'match'           => ['attribute' => 'importId'],
        ]);
    }

    /**
     * A tag in the given group. Groups are identified by a stable id derived from
     * the handle, so the target's id comparison lines up with what
     * {@see targetWithGroups()} hands back for the same handle.
     */
    protected function tag(string $group, mixed $match): Tag
    {
        $tag = new class() extends Tag {
            public mixed $importId = null;

            public function __construct()
            {
                // Skip Tag::init()'s Craft dependencies.
            }
        };

        $tag->groupId = crc32($group);
        $tag->importId = $match;

        return $tag;
    }

    /**
     * A target whose group lookup answers from a handle => name map.
     *
     * @param array<string, string> $groups
     */
    protected function targetWithGroups(array $groups): TagTarget
    {
        $target = new class() extends TagTarget {
            /** @var array<string, string> */
            public array $groups = [];

            protected function groupByHandle(string $handle): ?TagGroup
            {
                if (! isset($this->groups[$handle])) {
                    return null;
                }

                return new class($handle, $this->groups[$handle]) extends TagGroup {
                    public function __construct(string $handle, string $name)
                    {
                        $this->handle = $handle;
                        $this->name = $name;
                        $this->id = crc32($handle);
                    }
                };
            }
        };
        $target->groups = $groups;

        return $target;
    }
}
