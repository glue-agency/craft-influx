<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\GlobalSet;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\ItemProcessor;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * {@see ItemProcessor::resolve()} for a link whose target names ONE element from
 * its criteria — no match value is read, and the feed only contributes content.
 *
 * FIRST ITEM WINS. Every item would otherwise resolve to the same element and
 * overwrite the one before it, reporting a run of successful updates that hid the
 * mismatch between the feed's shape and the target's.
 */
class SingleElementResolutionTest extends Unit
{
    public function testTheFirstItemResolvesTheCriteriasElement(): void
    {
        $element = $this->element();
        $context = $this->context($this->target(requiresMatch: false, found: $element));

        $resolution = (new ItemProcessor())->resolve($context, $this->item());

        $this->assertSame($element, $resolution->element);
        $this->assertSame(SyncDecision::UPDATE, $resolution->decision);
        // No match value was read, so none is reported.
        $this->assertNull($resolution->matchValue);
    }

    public function testEveryLaterItemIsSkippedRatherThanOverwriting(): void
    {
        $context = $this->context($this->target(requiresMatch: false, found: $this->element()));
        $processor = new ItemProcessor();

        $processor->resolve($context, $this->item());
        $second = $processor->resolve($context, $this->item());
        $third = $processor->resolve($context, $this->item());

        foreach ([$second, $third] as $resolution) {
            $this->assertSame(SyncDecision::SKIP_SINGLE_ELEMENT_TAKEN, $resolution->decision);
            $this->assertNull($resolution->element);
        }
    }

    public function testTheClaimIsPerContextSoEachSitePassFillsItsOwnRow(): void
    {
        // Site-endpoint links run one pass per site, each with its own SyncContext
        // and so its own memo — the element has to be writable once per site.
        $target = $this->target(requiresMatch: false, found: $this->element());
        $processor = new ItemProcessor();

        $first = $processor->resolve($this->context($target), $this->item());
        $second = $processor->resolve($this->context($target), $this->item());

        $this->assertSame(SyncDecision::UPDATE, $first->decision);
        $this->assertSame(SyncDecision::UPDATE, $second->decision);
    }

    public function testUnresolvableCriteriaStillConsumeTheClaim(): void
    {
        // One item reports the real problem and the rest report the shape mismatch,
        // rather than every item repeating the same failure.
        $context = $this->context($this->target(requiresMatch: false, found: null));
        $processor = new ItemProcessor();

        $first = $processor->resolve($context, $this->item());
        $second = $processor->resolve($context, $this->item());

        // Global sets can't be created, so no element means nothing to write.
        $this->assertSame(SyncDecision::SKIP_NO_CREATE, $first->decision);
        $this->assertSame(SyncDecision::SKIP_SINGLE_ELEMENT_TAKEN, $second->decision);
    }

    public function testAMatchingLinkIsUntouched(): void
    {
        // The ordinary path still reads the match value and still skips an item that
        // carries none.
        $context = $this->context($this->target(requiresMatch: true, found: $this->element()));

        $resolution = (new ItemProcessor())->resolve($context, $this->item());

        $this->assertSame(SyncDecision::SKIP_NO_MATCH, $resolution->decision);
    }

    public function testTheAbstractTargetRefusesToResolveWithoutAnImplementation(): void
    {
        // Declaring requiresMatch() = false without findWithoutMatch() would
        // otherwise resolve every item to null and look like a feed problem.
        $target = new class() extends AbstractElementTarget {
            public static function elementType(): string
            {
                return GlobalSet::class;
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

        $this->expectException(InfluxException::class);

        $target->findWithoutMatch($this->link(requiresMatch: false));
    }

    // -- fixtures -------------------------------------------------------------

    protected function item(): RemoteItem
    {
        return new RemoteItem(['title' => 'Whatever']);
    }

    protected function element(): GlobalSet
    {
        return new class() extends GlobalSet {
            public function __construct()
            {
                // Skip GlobalSet::init()'s Craft dependencies.
            }
        };
    }

    protected function context(ElementTargetInterface $target): SyncContext
    {
        return new SyncContext($this->link($target->requiresMatch(new Link())), $target);
    }

    protected function link(bool $requiresMatch): Link
    {
        $link = new class() extends Link {
            public ?ElementTargetInterface $targetStub = null;

            protected function target(): ?ElementTargetInterface
            {
                return $this->targetStub;
            }
        };
        $link->handle = 'globals';
        $link->name = 'Globals';
        $link->elementType = GlobalSet::class;
        $link->endpoint = '@data/globals.json';
        $link->match = [];
        $link->processing = [ProcessingAction::UPDATE->value];
        $link->targetStub = $this->target(requiresMatch: $requiresMatch, found: null);

        return $link;
    }

    protected function target(bool $requiresMatch, ?GlobalSet $found): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static bool $requires = true;

            public ?GlobalSet $found = null;

            public static function elementType(): string
            {
                return GlobalSet::class;
            }

            public static function supportsCreating(): bool
            {
                return false;
            }

            public function requiresMatch(Link $link): bool
            {
                return static::$requires;
            }

            public function findWithoutMatch(Link $link, ?int $siteId = null): ?ElementInterface
            {
                return $this->found;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return $this->found;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new LogicException('Not exercised here.');
            }
        };

        $target::$requires = $requiresMatch;
        $target->found = $found;

        return $target;
    }
}
