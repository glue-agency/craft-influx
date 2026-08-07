<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\elements\Category;
use craft\models\CategoryGroup;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\CategoryTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * CategoryTarget: the group is both the scope and the ownership boundary, and
 * `parent` is the structural native that makes a category tree syncable.
 *
 * Categories here are anonymous Category subclasses with a skipped constructor
 * and an overridden group getter, so the spec runs without a booted Craft; the
 * group lookup and the parent resolution go through their `protected` seams.
 */
class CategoryTargetTest extends Unit
{
    public function testTheGroupCriterionGatesBothPredicates(): void
    {
        $target = $this->targetWithGroups(['topics' => 'Topics', 'regions' => 'Regions']);
        $link = $this->link(['group' => 'topics']);

        // In scope, no match value: structurally targeted, not yet claimed.
        $category = $this->category('topics', null);
        $this->assertTrue($target->targetsElement($link, $category));
        $this->assertFalse($target->claimsElement($link, $category));

        $category = $this->category('topics', 'abc');
        $this->assertTrue($target->targetsElement($link, $category));
        $this->assertTrue($target->claimsElement($link, $category));

        // Wrong group — out of scope even with a match value.
        $category = $this->category('regions', 'abc');
        $this->assertFalse($target->targetsElement($link, $category));
        $this->assertFalse($target->claimsElement($link, $category));
    }

    public function testAnUnscopedLinkTargetsAnyCategory(): void
    {
        $target = new CategoryTarget();

        $this->assertTrue($target->targetsElement($this->link([]), $this->category('anything', 'abc')));
    }

    public function testACategoryWithNoGroupIsNotTargeted(): void
    {
        // This predicate runs on every element edit screen, so an element whose
        // group isn't set has to answer "not mine" rather than throw out of
        // Category::getGroup().
        $target = $this->targetWithGroups(['topics' => 'Topics']);
        $category = $this->category('topics', 'abc');
        $category->groupId = null;

        $this->assertFalse($target->targetsElement($this->link(['group' => 'topics']), $category));
    }

    public function testANonCategoryIsNeverTargeted(): void
    {
        $target = new CategoryTarget();

        $notACategory = new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };

        $this->assertFalse($target->targetsElement($this->link(['group' => 'topics']), $notACategory));
    }

    public function testAScopedLinkClaimsExactlyItsGroup(): void
    {
        $this->assertSame(['topics'], (new CategoryTarget())->claimCells($this->link(['group' => 'topics'])));
    }

    public function testCriteriaLabelNamesTheGroupAndFallsBackToTheHandle(): void
    {
        $target = $this->targetWithGroups(['topics' => 'Topics']);

        $this->assertNull($target->criteriaLabel($this->link([])));
        $this->assertSame('Topics', $target->criteriaLabel($this->link(['group' => 'topics'])));
        // A group removed since the link was configured still reads as something.
        $this->assertSame('regions', $target->criteriaLabel($this->link(['group' => 'regions'])));
    }

    public function testParentResolvesThroughTheMatchStrategyAndReportsTheChange(): void
    {
        $target = $this->targetResolvingParents(['oslo' => 42]);
        $element = $this->category('topics', 'abc');

        $changed = $target->applyNativeAttribute(
            $this->context(),
            $element,
            'parent',
            new RemoteItem(['parent' => 'oslo']),
            FieldMapping::fromConfig('parent', ['node' => 'parent', 'options' => ['match' => 'slug']]),
        );

        $this->assertTrue($changed);
        $this->assertSame(42, $element->getParentId());
    }

    public function testAnEmptyParentMovesTheCategoryToTheRoot(): void
    {
        $target = $this->targetResolvingParents([]);
        $element = $this->category('topics', 'abc');

        $changed = $target->applyNativeAttribute(
            $this->context(),
            $element,
            'parent',
            new RemoteItem(['parent' => null]),
            FieldMapping::fromConfig('parent', ['node' => 'parent']),
        );

        // Nothing was set and nothing was there — the row reports no change.
        $this->assertFalse($changed);
        $this->assertNull($element->getParentId());
    }

    public function testARowNamingItselfAsItsParentIsIgnored(): void
    {
        // A feed that carries a `parent` column routinely uses the row's own key as
        // its "no parent" sentinel; letting that through would fail the save.
        $target = $this->targetResolvingParents(['oslo' => 7]);
        $element = $this->category('topics', 'abc');
        $element->id = 7;
        // At the root, so the "before" read costs no query.
        $element->level = 1;

        $target->applyNativeAttribute(
            $this->context(),
            $element,
            'parent',
            new RemoteItem(['parent' => 'oslo']),
            FieldMapping::fromConfig('parent', ['node' => 'parent', 'options' => ['match' => 'slug']]),
        );

        $this->assertNull($element->getParentId());
    }

    // -- fixtures -------------------------------------------------------------

    protected function link(array $criteria): Link
    {
        return FakeLink::make([
            'elementType'     => Category::class,
            'elementCriteria' => $criteria,
            'match'           => ['attribute' => 'importId'],
        ]);
    }

    protected function context(): SyncContext
    {
        return new SyncContext(link: $this->link(['group' => 'topics']), target: new CategoryTarget());
    }

    /**
     * A category in the given group. Groups are identified by a stable id derived
     * from the handle, so the target's id comparison lines up with what
     * {@see targetWithGroups()} hands back for the same handle.
     */
    protected function category(string $group, mixed $match): Category
    {
        $category = new class() extends Category {
            public mixed $importId = null;

            public function __construct()
            {
                // Skip Category::init()'s Craft dependencies.
            }
        };

        $category->groupId = self::groupId($group);
        // The link's match attribute is `importId`; a real property, so the target
        // reads it directly rather than through the field magic getter.
        $category->importId = $match;

        return $category;
    }

    /** A handle's stand-in id — one rule, both sides of the comparison. */
    protected static function groupId(string $handle): int
    {
        return crc32($handle);
    }

    /**
     * A target whose group lookup answers from a handle => name map.
     *
     * @param array<string, string> $groups
     */
    protected function targetWithGroups(array $groups): CategoryTarget
    {
        $target = new class() extends CategoryTarget {
            /** @var array<string, string> */
            public array $groups = [];

            protected function groupByHandle(string $handle): ?CategoryGroup
            {
                if (! isset($this->groups[$handle])) {
                    return null;
                }

                return new class($handle, $this->groups[$handle]) extends CategoryGroup {
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

    /**
     * A target whose parent lookup answers from a value => id map, standing in for
     * the query {@see CategoryTarget::findCategory()} would run.
     *
     * @param array<string, int> $parents
     */
    protected function targetResolvingParents(array $parents): CategoryTarget
    {
        $target = new class() extends CategoryTarget {
            /** @var array<string, int> */
            public array $parents = [];

            protected function findCategory(SyncContext $context, string $match, mixed $value): ?Category
            {
                $id = $this->parents[(string) $value] ?? null;

                if ($id === null) {
                    return null;
                }

                $category = new class() extends Category {
                    public function __construct()
                    {
                    }
                };
                $category->id = $id;

                return $category;
            }
        };
        $target->parents = $parents;

        return $target;
    }
}
