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
 * GlobalSetTarget: update-only, and matchless.
 *
 * What makes it different from every other target: it can't create, it can't be
 * swept, and it needs no match value — its `set` criterion already names the one
 * element, so there is nothing to disambiguate and no native row to offer. The
 * `handle` CLAIM outlives the row it used to describe, as a guard against a
 * mapping saved before the row was removed reaching `$element->handle`.
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

    public function testHandleStaysClaimedEvenThoughNoRowOffersItAnyMore(): void
    {
        // The claim is now a guard, not a UI concern: nothing prunes a stale
        // `mappings.handle` from stored config (LinksService::pruneMappings() skips
        // project-config/apply and bails on a layout with no custom fields), and
        // without the claim it would route through the generic native path and
        // assign $element->handle from the feed — rewriting project config.
        $target = new GlobalSetTarget();
        $link = $this->link(['set' => 'siteSettings']);

        $this->assertTrue($target->ownsAttribute($link, 'handle'));
        $this->assertFalse($target->ownsAttribute($link, 'someField'));
    }

    public function testItNeedsNoMatchAndOffersNoNativeRowOrMatchOption(): void
    {
        $target = new GlobalSetTarget();
        $link = $this->link(['set' => 'siteSettings']);

        $this->assertFalse($target->requiresMatch($link));
        $this->assertSame([], $target->matchableNativeAttributes($link));

        // No native group at all — a global set has no attribute a feed may write,
        // so an unresolvable set reports nothing rather than a lone `handle` row.
        $fields = $target->getMappableFields($this->link([]));
        $this->assertSame([], array_map(static fn(MappableField $field): string => $field->handle, $fields));
    }

    public function testItResolvesTheCriterionsSetWithoutConsultingTheFeed(): void
    {
        $target = $this->targetResolving($this->set('footer'));

        $this->assertNotNull($target->findWithoutMatch($this->link(['set' => 'footer'])));

        // findByMatchValue stays honest for an out-of-band caller: same element,
        // whatever value it was handed.
        $this->assertNotNull($target->findByMatchValue($this->link(['set' => 'footer']), 'anything'));
        $this->assertNotNull($target->findByMatchValue($this->link(['set' => 'footer']), null));
    }

    public function testAnUnsetCriterionResolvesNothing(): void
    {
        // The criterion is what identifies the element now, so without one there is
        // nothing to write — where before, "no criterion" meant the feed's own value
        // stood.
        $target = $this->targetResolving($this->set('footer'));

        $this->assertNull($target->findWithoutMatch($this->link([])));
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

            protected function queryOne(string $handle, ?int $siteId): ?GlobalSet
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
