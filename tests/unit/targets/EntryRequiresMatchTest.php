<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\models\Section;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * {@see EntryTarget::requiresMatch()} — the reason the capability is Link-scoped
 * rather than static like {@see EntryTarget::supportsCreating()}.
 *
 * A Craft Single holds exactly one entry, which the section criterion already
 * names, so a link scoped to one needs no match value. Whether that's the case
 * depends on the LINK's section, not on the element type — which is why no static
 * flag could answer it.
 *
 * Section resolution goes through the target's own `section()` seam, so these run
 * without a booted Craft.
 */
class EntryRequiresMatchTest extends Unit
{
    public function testALinkScopedToASingleNeedsNoMatch(): void
    {
        $this->assertFalse($this->target(Section::TYPE_SINGLE)->requiresMatch($this->link('home')));
    }

    public function testAChannelLinkStillNeedsAMatch(): void
    {
        $this->assertTrue($this->target(Section::TYPE_CHANNEL)->requiresMatch($this->link('blog')));
    }

    public function testAStructureLinkStillNeedsAMatch(): void
    {
        $this->assertTrue($this->target(Section::TYPE_STRUCTURE)->requiresMatch($this->link('pages')));
    }

    public function testAnUnsetSectionStillNeedsAMatch(): void
    {
        // "Can't tell" must not relax the requirement: a half-configured link that
        // quietly stopped needing a match would resolve every item to nothing and
        // still pass validation.
        $this->assertTrue($this->target(Section::TYPE_SINGLE)->requiresMatch($this->link(null)));
    }

    public function testASectionThatNoLongerExistsStillNeedsAMatch(): void
    {
        $this->assertTrue($this->target(null)->requiresMatch($this->link('deleted')));
    }

    public function testResolvingWithoutAMatchNeedsASection(): void
    {
        // The criterion is what identifies the entry, so without one there is
        // nothing to resolve — and no query is attempted.
        $this->assertNull($this->target(Section::TYPE_SINGLE)->findWithoutMatch($this->link(null)));
    }

    protected function link(?string $section): Link
    {
        return FakeLink::make([
            'elementType'     => Entry::class,
            'elementCriteria' => $section !== null ? ['section' => $section] : [],
        ]);
    }

    /**
     * A target whose section lookup answers with a section of the given type, or
     * null for "no such section".
     *
     * The stub keeps the seam's own precondition — no section criterion means no
     * section — rather than answering from `$sectionType` alone. Without that it
     * would report a Single for a link that names no section at all, and the
     * unset-criterion case would be testing the stub instead of the target.
     */
    protected function target(?string $type): EntryTarget
    {
        $target = new class() extends EntryTarget {
            public ?string $sectionType = null;

            protected function section(Link $link): ?Section
            {
                if ($this->sectionType === null || $link->criterion(self::CRITERIA_SECTION) === null) {
                    return null;
                }

                $section = new Section();
                $section->type = $this->sectionType;

                return $section;
            }
        };
        $target->sectionType = $type;

        return $target;
    }
}
