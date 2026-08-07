<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * {@see Link::pruneProcessingForTarget()}: a link to an element type its target
 * can't create loses the `create` policy on save. Since
 * {@see ProcessingAction::defaults()} includes `create`, that's the state every
 * new link starts in — so this is the healing step that keeps a Global Set link
 * from sitting on a policy its run would only ignore.
 *
 * The registered target is injected through the `target()` seam, since the
 * plugin singleton isn't bootstrapped in a unit test.
 */
class LinkProcessingTargetPruneTest extends Unit
{
    public function testCreateIsDroppedForANonCreatingTarget(): void
    {
        $link = $this->link(GlobalSet::class, false, ProcessingAction::defaults());
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
        $this->assertSame([ProcessingAction::CREATE->value], $dropped);
    }

    public function testNothingIsDroppedForACreatingTarget(): void
    {
        $link = $this->link(Entry::class, true, ProcessingAction::defaults());
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame(ProcessingAction::defaults(), $link->processing);
        $this->assertSame([], $dropped);
    }

    public function testOtherPoliciesSurviveTheDrop(): void
    {
        // Only `create` is this target's business. The sweep policies are gated by
        // supportsSweeping() and report a skipped sweep at run time instead.
        $link = $this->link(GlobalSet::class, false, [
            ProcessingAction::CREATE->value,
            ProcessingAction::UPDATE->value,
            ProcessingAction::DISABLE->value,
        ]);
        $link->pruneProcessingForTarget();

        $this->assertSame(
            [ProcessingAction::UPDATE->value, ProcessingAction::DISABLE->value],
            $link->processing,
        );
    }

    public function testIsIdempotent(): void
    {
        $link = $this->link(GlobalSet::class, false, ProcessingAction::defaults());
        $link->pruneProcessingForTarget();
        $second = $link->pruneProcessingForTarget();

        $this->assertSame([], $second);
        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
    }

    public function testAnUnregisteredElementTypeIsLeftAlone(): void
    {
        // No target means nothing knows whether the type can be created — leave the
        // config as configured rather than guessing.
        $link = $this->link('vendor\elements\Widget', null, ProcessingAction::defaults());

        $this->assertSame([], $link->pruneProcessingForTarget());
        $this->assertSame(ProcessingAction::defaults(), $link->processing);
    }

    /**
     * A link whose registered target is injected and whose creating capability is
     * whatever the test asks for. A null $creating stands for "no target
     * registered".
     *
     * @param list<string> $processing
     */
    protected function link(string $elementType, ?bool $creating, array $processing): Link
    {
        $link = new class() extends Link {
            public ?ElementTargetInterface $targetStub = null;

            protected function target(): ?ElementTargetInterface
            {
                return $this->targetStub;
            }
        };
        $link->elementType = $elementType;
        $link->processing = $processing;
        $link->targetStub = $creating === null ? null : $this->target($elementType, $creating);

        return $link;
    }

    protected function target(string $elementType, bool $creating): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static string $type = '';

            public static bool $creating = true;

            public static function elementType(): string
            {
                return static::$type;
            }

            public static function supportsCreating(): bool
            {
                return static::$creating;
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

        $target::$type = $elementType;
        $target::$creating = $creating;

        return $target;
    }
}
