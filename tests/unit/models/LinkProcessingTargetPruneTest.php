<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * {@see Link::pruneProcessingForTarget()}: a link loses every policy its target
 * can't honour on save — `create` for a target that can't create (a Global Set),
 * and the four missing-element policies for one that can't enumerate what the
 * link owns (a User). Since {@see ProcessingAction::defaults()} includes
 * `create`, the Global Set case is the state every new link starts in, so this is
 * the healing step that keeps stored config from carrying a policy no run reads.
 *
 * The registered target is injected through the `target()` seam, since the
 * plugin singleton isn't bootstrapped in a unit test.
 */
class LinkProcessingTargetPruneTest extends Unit
{
    public function testCreateIsDroppedForANonCreatingTarget(): void
    {
        $link = $this->link(GlobalSet::class, creating: false, sweeping: true, processing: ProcessingAction::defaults());
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
        $this->assertSame([ProcessingAction::CREATE->value], array_column($dropped, 'action'));
    }

    public function testSweepPoliciesAreDroppedForANonSweepingTarget(): void
    {
        // A User link: it can create and update, but "everything this link owns"
        // has no scoping dimension, so no missing-element policy can run.
        $link = $this->link(User::class, creating: true, sweeping: false, processing: [
            ProcessingAction::CREATE->value,
            ProcessingAction::UPDATE->value,
            ProcessingAction::DISABLE->value,
            ProcessingAction::DELETE->value,
        ]);
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame(ProcessingAction::defaults(), $link->processing);
        $this->assertSame(
            [ProcessingAction::DISABLE->value, ProcessingAction::DELETE->value],
            array_column($dropped, 'action'),
        );
    }

    public function testForSitePoliciesAreDroppedForANonSweepingTarget(): void
    {
        $link = $this->link(User::class, creating: true, sweeping: false, processing: [
            ProcessingAction::UPDATE->value,
            ProcessingAction::DISABLE_FOR_SITE->value,
            ProcessingAction::DELETE_FOR_SITE->value,
        ]);
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
        $this->assertSame(
            [ProcessingAction::DISABLE_FOR_SITE->value, ProcessingAction::DELETE_FOR_SITE->value],
            array_column($dropped, 'action'),
        );
    }

    public function testBothCapabilitiesArePrunedInOneSave(): void
    {
        // The Global Set shape: neither creatable nor sweepable, so `update` is all
        // that can survive however the link was configured.
        $link = $this->link(GlobalSet::class, creating: false, sweeping: false, processing: [
            ProcessingAction::CREATE->value,
            ProcessingAction::UPDATE->value,
            ProcessingAction::DISABLE->value,
        ]);
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
        $this->assertSame(
            [ProcessingAction::CREATE->value, ProcessingAction::DISABLE->value],
            array_column($dropped, 'action'),
        );
    }

    public function testEveryDropCarriesItsReason(): void
    {
        // The reason travels with the drop so the builder's notice and the Feed Me
        // importer's warning both name why, and can group two reasons per save.
        $link = $this->link(GlobalSet::class, creating: false, sweeping: false, processing: [
            ProcessingAction::CREATE->value,
            ProcessingAction::DELETE->value,
        ]);
        $reasons = array_column($link->pruneProcessingForTarget(), 'reason', 'action');

        $this->assertStringContainsString('create elements', $reasons[ProcessingAction::CREATE->value]);
        $this->assertStringContainsString('missing from the feed', $reasons[ProcessingAction::DELETE->value]);
    }

    public function testNothingIsDroppedForAFullyCapableTarget(): void
    {
        $processing = ProcessingAction::values();
        $link = $this->link(Entry::class, creating: true, sweeping: true, processing: $processing);
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame($processing, $link->processing);
        $this->assertSame([], $dropped);
    }

    public function testIsIdempotent(): void
    {
        $link = $this->link(GlobalSet::class, creating: false, sweeping: false, processing: [
            ProcessingAction::CREATE->value,
            ProcessingAction::UPDATE->value,
            ProcessingAction::DELETE->value,
        ]);
        $link->pruneProcessingForTarget();
        $second = $link->pruneProcessingForTarget();

        $this->assertSame([], $second);
        $this->assertSame([ProcessingAction::UPDATE->value], $link->processing);
    }

    public function testAnUnknownPolicySurvives(): void
    {
        // Same contract as migrateProcessingForEndpointShape(): nothing can say
        // whether a policy the enum can't name is supported, so it's left alone.
        $link = $this->link(User::class, creating: true, sweeping: false, processing: [
            ProcessingAction::UPDATE->value,
            'archive-missing',
            ProcessingAction::DELETE->value,
        ]);
        $dropped = $link->pruneProcessingForTarget();

        $this->assertSame([ProcessingAction::UPDATE->value, 'archive-missing'], $link->processing);
        $this->assertSame([ProcessingAction::DELETE->value], array_column($dropped, 'action'));
    }

    public function testAnUnregisteredElementTypeIsLeftAlone(): void
    {
        // No target means nothing knows what the type supports — leave the config
        // as configured rather than guessing.
        $processing = [ProcessingAction::CREATE->value, ProcessingAction::DELETE->value];
        $link = $this->link('vendor\elements\Widget', creating: null, sweeping: null, processing: $processing);

        $this->assertSame([], $link->pruneProcessingForTarget());
        $this->assertSame($processing, $link->processing);
    }

    /**
     * A link whose registered target is injected and whose capabilities are
     * whatever the test asks for. A null $creating stands for "no target
     * registered".
     *
     * @param list<string> $processing
     */
    protected function link(string $elementType, ?bool $creating, ?bool $sweeping, array $processing): Link
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
        $link->targetStub = $creating === null ? null : $this->target($elementType, $creating, (bool) $sweeping);

        return $link;
    }

    protected function target(string $elementType, bool $creating, bool $sweeping): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static string $type = '';

            public static bool $creating = true;

            public static bool $sweeping = true;

            public static function elementType(): string
            {
                return static::$type;
            }

            public static function supportsCreating(): bool
            {
                return static::$creating;
            }

            public static function supportsSweeping(): bool
            {
                return static::$sweeping;
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
        $target::$sweeping = $sweeping;

        return $target;
    }
}
