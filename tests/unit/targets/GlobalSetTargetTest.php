<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\targets\GlobalSetTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * GlobalSetTarget: update-only, and matched on the one identifier a global set
 * has.
 *
 * The three things worth pinning are the ones that make it different from every
 * other target — it can't create, it can't be swept, and its `handle` row exists
 * only so the match value has a source node to come from (nothing writes it,
 * since a handle is project config).
 */
class GlobalSetTargetTest extends Unit
{
    public function testItCanNeitherCreateNorSweep(): void
    {
        // A global set exists because project config declares it; a feed fills it
        // in and nothing more.
        $this->assertFalse(GlobalSetTarget::supportsCreating());
        $this->assertFalse(GlobalSetTarget::supportsSweeping());
    }

    public function testGlobalSetContentIsPerSiteSoLinksCanRunPerSite(): void
    {
        // Unlike User, which is genuinely global.
        $this->assertTrue(GlobalSetTarget::supportsMultiSite());
    }

    public function testBuildNewRefusesRatherThanConjureASet(): void
    {
        $this->expectException(InfluxException::class);

        (new GlobalSetTarget())->buildNew($this->link(['set' => 'siteSettings']));
    }

    public function testTheHandleRowIsClaimedSoNothingWritesIt(): void
    {
        $target = new GlobalSetTarget();
        $link = $this->link(['set' => 'siteSettings']);

        $this->assertTrue($target->ownsAttribute($link, 'handle'));
        $this->assertFalse($target->ownsAttribute($link, 'someField'));
    }

    public function testTheHandleRowExistsSoTheMatchValueHasASource(): void
    {
        // Link::validateMatch() requires the match attribute to have a mapping with
        // a source node, so the matchable identifier needs a row of its own.
        $fields = (new GlobalSetTarget())->getMappableFields($this->link([]));
        $handles = array_map(static fn(MappableField $field): string => $field->handle, $fields);

        $this->assertSame(['handle'], $handles);
        $this->assertContains('handle', array_column(
            (new GlobalSetTarget())->matchableNativeAttributes($this->link([])),
            'value',
        ));
    }

    public function testTheSetCriterionGatesTargeting(): void
    {
        $target = new GlobalSetTarget();
        $link = $this->link(['set' => 'siteSettings']);

        $this->assertTrue($target->targetsElement($link, $this->set('siteSettings')));
        $this->assertFalse($target->targetsElement($link, $this->set('footer')));

        // Not a global set at all.
        $notASet = new class() extends Entry {
            public function __construct()
            {
                // Skip Entry::init()'s Craft dependencies.
            }
        };
        $this->assertFalse($target->targetsElement($link, $notASet));
    }

    public function testTheCriterionRejectsAMatchOutsideIt(): void
    {
        // The usual match attribute here IS `handle`, so scoping the query on the
        // criterion would have overwritten the feed's own value and resolved every
        // item to the configured set. The criterion is a boundary, not a default.
        $target = $this->targetResolving($this->set('footer'));

        $this->assertNull($target->findByMatchValue($this->link(['set' => 'siteSettings']), 'footer'));
        $this->assertNotNull($target->findByMatchValue($this->link(['set' => 'footer']), 'footer'));
        // No criterion configured: whatever the feed named stands.
        $this->assertNotNull($target->findByMatchValue($this->link([]), 'footer'));
    }

    public function testAScopedLinkClaimsExactlyItsSet(): void
    {
        $this->assertSame(['siteSettings'], (new GlobalSetTarget())->claimCells($this->link(['set' => 'siteSettings'])));
    }

    public function testCriteriaLabelNamesTheSetAndFallsBackToTheHandle(): void
    {
        $target = $this->targetWithSets(['siteSettings' => 'Site Settings']);

        $this->assertNull($target->criteriaLabel($this->link([])));
        $this->assertSame('Site Settings', $target->criteriaLabel($this->link(['set' => 'siteSettings'])));
        $this->assertSame('footer', $target->criteriaLabel($this->link(['set' => 'footer'])));
    }

    // -- fixtures -------------------------------------------------------------

    protected function link(array $criteria): Link
    {
        return FakeLink::make([
            'elementType'     => GlobalSet::class,
            'elementCriteria' => $criteria,
            'match'           => ['attribute' => 'handle'],
        ]);
    }

    protected function set(string $handle): GlobalSet
    {
        $set = new class() extends GlobalSet {
            public function __construct()
            {
                // Skip GlobalSet::init()'s Craft dependencies.
            }
        };
        $set->handle = $handle;

        return $set;
    }

    /** A target whose lookup always answers with the given set. */
    protected function targetResolving(GlobalSet $set): GlobalSetTarget
    {
        $target = new class() extends GlobalSetTarget {
            public ?GlobalSet $found = null;

            protected function queryOne(string $matchAttr, mixed $matchValue, ?int $siteId): ?GlobalSet
            {
                return $this->found;
            }
        };
        $target->found = $set;

        return $target;
    }

    /**
     * A target whose set lookup answers from a handle => name map.
     *
     * @param array<string, string> $sets
     */
    protected function targetWithSets(array $sets): GlobalSetTarget
    {
        $target = new class() extends GlobalSetTarget {
            /** @var array<string, string> */
            public array $sets = [];

            protected function setByHandle(string $handle): ?GlobalSet
            {
                if (! isset($this->sets[$handle])) {
                    return null;
                }

                $set = new class() extends GlobalSet {
                    public function __construct()
                    {
                    }
                };
                $set->handle = $handle;
                $set->name = $this->sets[$handle];

                return $set;
            }
        };
        $target->sets = $sets;

        return $target;
    }
}
