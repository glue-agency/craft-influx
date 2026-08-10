<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\GlobalSet;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * {@see Link::requiresMatch()} and {@see Link::pruneMatchForTarget()} — the model
 * half of making a match key optional.
 *
 * A link whose target names one element from its criteria (a Global Set, an Entry
 * link scoped to a Craft Single) needs no match value, so validation stops
 * demanding one and the save drops a stale one. Everything else is unchanged, and
 * so is the answer for a target nobody can resolve.
 *
 * The registered target is injected through the `target()` seam, since the plugin
 * singleton isn't bootstrapped in a unit test.
 */
class LinkRequiresMatchTest extends Unit
{
    public function testAMatchIsRequiredByDefault(): void
    {
        $this->assertTrue($this->link(requiresMatch: true)->requiresMatch());
    }

    public function testATargetThatNamesOneElementNeedsNoMatch(): void
    {
        $this->assertFalse($this->link(requiresMatch: false)->requiresMatch());
    }

    public function testAnUnregisteredElementTypeStillExpectsAMatch(): void
    {
        // Nothing can say a match is unnecessary for a target it can't find, and
        // guessing "not needed" would turn a typo'd elementType into a link that
        // resolves every item to nothing without ever failing validation.
        $this->assertTrue($this->link(requiresMatch: null)->requiresMatch());
    }

    public function testValidationDemandsAMatchWhenTheTargetNeedsOne(): void
    {
        $link = $this->link(requiresMatch: true, match: []);
        $link->validateMatch('match');

        $this->assertNotEmpty($link->getErrors('match'));
    }

    public function testValidationAcceptsNoMatchWhenTheTargetNeedsNone(): void
    {
        $link = $this->link(requiresMatch: false, match: []);
        $link->validateMatch('match');

        $this->assertSame([], $link->getErrors('match'));
    }

    public function testAConfiguredMatchIsStillCheckedForCoherence(): void
    {
        // The exemption is from NEEDING a match, not from a configured one being
        // well-formed: an attribute with no mapped source node is still an error.
        $link = $this->link(requiresMatch: false, match: ['attribute' => 'handle']);
        $link->validateMatch('match');

        $this->assertNotEmpty($link->getErrors('match'));
    }

    public function testTheStaleMatchIsPrunedForANoMatchTarget(): void
    {
        $link = $this->link(requiresMatch: false, match: ['attribute' => 'handle']);

        $this->assertSame('handle', $link->pruneMatchForTarget());
        $this->assertSame([], $link->match);
    }

    public function testAMatchIsKeptWhenTheTargetNeedsOne(): void
    {
        $link = $this->link(requiresMatch: true, match: ['attribute' => 'importId']);

        $this->assertNull($link->pruneMatchForTarget());
        $this->assertSame(['attribute' => 'importId'], $link->match);
    }

    public function testPruningIsIdempotentAndANoOpWithoutAMatch(): void
    {
        $link = $this->link(requiresMatch: false, match: ['attribute' => 'handle']);
        $link->pruneMatchForTarget();

        $this->assertNull($link->pruneMatchForTarget());
        $this->assertSame([], $link->match);
    }

    /**
     * A link whose registered target answers `requiresMatch()` as asked. A null
     * $requiresMatch stands for "no target registered".
     */
    protected function link(?bool $requiresMatch, ?array $match = null): Link
    {
        $link = new class() extends Link {
            public ?ElementTargetInterface $targetStub = null;

            protected function target(): ?ElementTargetInterface
            {
                return $this->targetStub;
            }
        };
        $link->handle = 'globals';
        $link->elementType = GlobalSet::class;
        $link->match = $match ?? ['attribute' => 'importId'];
        $link->targetStub = $requiresMatch === null ? null : $this->target($requiresMatch);

        return $link;
    }

    protected function target(bool $requiresMatch): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static bool $requires = true;

            public static function elementType(): string
            {
                return GlobalSet::class;
            }

            public function requiresMatch(Link $link): bool
            {
                return static::$requires;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new LogicException('Not exercised here.');
            }
        };

        $target::$requires = $requiresMatch;

        return $target;
    }
}
