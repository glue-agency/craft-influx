<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\item\ItemProcessor;
use GlueAgency\Influx\sync\item\ItemSyncResult;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * {@see ItemProcessor::commit()}: the persist phase. Two things are specced here
 * that used to be hardcoded Craft calls, so neither could be asserted without a
 * database:
 *
 *   - the save routes through the TARGET ({@see AbstractElementTarget::save()}),
 *     the same surface the sweep's disable/delete go through, so a third-party
 *     target can save with its own flags;
 *   - a failed save reports something an operator can act on. Saves run with
 *     validation OFF, so `getErrors()` is normally empty on failure and the old
 *     `json_encode(getErrors())` could only ever say `[]`.
 *
 * The target is a spy over the base, so no Craft boot and no database.
 */
class ItemCommitTest extends Unit
{
    public function testAChangedElementIsSavedThroughTheTarget(): void
    {
        $target = $this->target(saves: true);
        $context = $this->context($target);

        $result = (new ItemProcessor())->commit($context, $this->draft(changed: true));

        $this->assertSame(1, $target->saveCalls, 'The engine never calls Craft directly — the target owns the write.');
        $this->assertSame(ItemAction::UPDATED, $result->action);
        $this->assertNull($result->message);
    }

    public function testAnUnchangedElementIsNotSavedButStillRunsAfterCommit(): void
    {
        $target = $this->target(saves: true);
        $context = $this->context($target);

        (new ItemProcessor())->commit($context, $this->draft(changed: false));

        $this->assertSame(0, $target->saveCalls);
        $this->assertSame(1, $target->afterCommitCalls);
    }

    public function testADryRunPersistsNothing(): void
    {
        $target = $this->target(saves: true);
        $context = $this->context($target, dryRun: true);

        (new ItemProcessor())->commit($context, $this->draft(changed: true));

        $this->assertSame(0, $target->saveCalls);
        $this->assertSame(0, $target->afterCommitCalls);
    }

    public function testAFailedSaveWithoutValidationErrorsSaysWhatActuallyHappened(): void
    {
        $target = $this->target(saves: false);
        $context = $this->context($target);

        $result = (new ItemProcessor())->commit($context, $this->draft(changed: true));

        $this->assertSame(ItemAction::ERROR, $result->action);
        $this->assertNotSame('[]', $result->message, 'An empty error bag is not a report.');
        $this->assertStringContainsString('beforeSave', (string) $result->message);
        $this->assertSame(0, $target->afterCommitCalls, 'A failed commit has nothing to reconcile.');
    }

    public function testAFailedSaveReportsRealErrorsWhenThereAreAny(): void
    {
        $target = $this->target(saves: false);
        $context = $this->context($target);
        $draft = $this->draft(changed: true);
        $draft->element->addError('title', 'Title cannot be blank.');

        $result = (new ItemProcessor())->commit($context, $draft);

        $this->assertSame(ItemAction::ERROR, $result->action);
        $this->assertStringContainsString('Title cannot be blank.', (string) $result->message);
    }

    // -- fixtures -------------------------------------------------------------

    protected function draft(bool $changed): ItemSyncResult
    {
        return new ItemSyncResult(
            decision: SyncDecision::UPDATE,
            action: $changed ? ItemAction::UPDATED : ItemAction::UNCHANGED,
            matchValue: 'abc',
            element: $this->element(),
            isNew: false,
            changed: $changed,
        );
    }

    protected function context(AbstractElementTarget $target, bool $dryRun = false): SyncContext
    {
        return new SyncContext(link: FakeLink::make(), target: $target, dryRun: $dryRun);
    }

    /**
     * A target counting its writes, with the save outcome dialled in. Everything
     * else is the base's.
     */
    protected function target(bool $saves): AbstractElementTarget
    {
        $target = new class() extends AbstractElementTarget {
            public bool $saves = true;
            public int $saveCalls = 0;
            public int $afterCommitCalls = 0;

            public static function elementType(): string
            {
                return ElementInterface::class;
            }

            public function save(ElementInterface $element): bool
            {
                $this->saveCalls++;

                return $this->saves;
            }

            public function afterCommit(SyncContext $context, ElementInterface $element, bool $isNew): void
            {
                $this->afterCommitCalls++;
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new RuntimeException('not needed');
            }
        };
        $target->saves = $saves;

        return $target;
    }

    protected function element(): Element
    {
        return new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };
    }
}
