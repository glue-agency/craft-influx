<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\models\FieldLayout;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\ItemRunner;
use GlueAgency\Influx\sync\item\ItemSyncResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\web\ItemRowPresenter;

/**
 * {@see ItemRunner}'s post-commit identity pass — the snapshot's back-fill.
 * Children are derived during populate, BEFORE the owner element saves, so a
 * block the sync adds has no element to chip; by the time the log snapshot is
 * captured the blocks exist, and the runner hands each row's children back to
 * the strategy that produced them.
 *
 * Bootless: the runner's plugin touchpoints are two protected seams (the
 * logging gate and the strategy lookup), the presenter is injectable, and the
 * service is only ever an event sender, so a mock suffices. The pass itself —
 * which rows it walks, and when it runs at all — is what's specced here; the
 * pairing lives in the strategy ({@see \GlueAgency\Influx\Tests\unit\fields\MatrixFieldTest}).
 */
class ItemRunnerSnapshotTest extends Unit
{
    public function testAWrittenItemsChildrenCarryIdentityBeforeTheyArePresented(): void
    {
        foreach ([ItemAction::CREATED, ItemAction::UPDATED] as $action) {
            $children = [
                new ChildResult(blockType: 'season', action: 'added'),
                new ChildResult(blockType: 'season', action: 'added'),
            ];
            $element = $this->element(['seasons']);
            $result = $this->outcome($action, $element, [
                $this->row('seasons', $children),
                $this->row('title'),
            ]);

            $runner = $this->runner();
            $snapshot = $runner->exposedSnapshot($this->context(), $result);

            $this->assertSame([['presented' => true]], $snapshot);
            $this->assertSame(1, $runner->strategyCalls, "One strategy per row that nests something ({$action->value}).");
            $this->assertSame('seasons', $runner->strategy->calls[0]['handle']);
            $this->assertSame($element, $runner->strategy->calls[0]['element']);

            // The presenter was handed the very rows the pass had just filled —
            // the back-fill has to land before presentation or the snapshot keeps
            // the nulls forever.
            $presented = $runner->presenter->presented[0]->children;
            $this->assertSame($children, $presented);
            $this->assertSame([$element, $element], array_column($presented, 'element'));
        }
    }

    public function testANonWritingOutcomeNeverTouchesTheChildren(): void
    {
        foreach ([ItemAction::UNCHANGED, ItemAction::SKIPPED, ItemAction::ERROR] as $action) {
            $children = [new ChildResult(blockType: 'season', action: 'unchanged')];
            $result = $this->outcome($action, $this->element(['seasons']), [$this->row('seasons', $children)]);

            $runner = $this->runner();
            $runner->exposedSnapshot($this->context(), $result);

            $this->assertSame(0, $runner->strategyCalls, "Nothing was saved for a {$action->value} item.");
            $this->assertNull($children[0]->element);
        }
    }

    public function testRowsWithNothingToFillAskNoStrategy(): void
    {
        $result = $this->outcome(ItemAction::CREATED, $this->element(['seasons']), [
            // A native row: no craft field on the layout, so no strategy to ask.
            $this->row('title', [new ChildResult(blockType: 'season', action: 'added')]),
            // A row that nests nothing at all.
            $this->row('seasons'),
            // …and one whose children came back empty.
            $this->row('other', []),
        ]);

        $runner = $this->runner();
        $runner->exposedSnapshot($this->context(), $result);

        $this->assertSame(0, $runner->strategyCalls);
    }

    public function testLoggingOffPresentsNothingAndFillsNothing(): void
    {
        $children = [new ChildResult(blockType: 'season', action: 'added')];
        $result = $this->outcome(ItemAction::CREATED, $this->element(['seasons']), [$this->row('seasons', $children)]);

        $runner = $this->runner(logging: false);

        $this->assertNull($runner->exposedSnapshot($this->context(), $result));
        $this->assertSame(0, $runner->strategyCalls, 'No row is written, so nothing consumes a back-fill.');
        $this->assertNull($children[0]->element);
    }

    public function testAnElementLessOutcomeIsSkippedEntirely(): void
    {
        $result = $this->outcome(ItemAction::CREATED, null, [
            $this->row('seasons', [new ChildResult(blockType: 'season', action: 'added')]),
        ]);

        $runner = $this->runner();

        $this->assertNull($runner->exposedSnapshot($this->context(), $result));
        $this->assertSame(0, $runner->strategyCalls);
    }

    // -- fixtures -------------------------------------------------------------

    /**
     * An {@see ItemRunner} with its two plugin seams stubbed: the logging gate
     * dialled in, and the strategy lookup answered by a recording spy that stamps
     * the owner element onto every child it is handed (the observable stand-in for
     * a real strategy's saved-block pairing).
     */
    protected function runner(bool $logging = true): ItemRunner
    {
        return new class($this->createMock(SynchronizationService::class), $this->presenter(), $this->spy(), $logging) extends ItemRunner {
            public ItemRowPresenter $presenter;

            public Field $strategy;

            public bool $logging = true;

            public int $strategyCalls = 0;

            public function __construct(
                SynchronizationService $service,
                ItemRowPresenter $presenter,
                Field $strategy,
                bool $logging,
            ) {
                parent::__construct($service, null, $presenter);
                $this->presenter = $presenter;
                $this->strategy = $strategy;
                $this->logging = $logging;
            }

            public function exposedSnapshot(SyncContext $context, ItemSyncResult $result): ?array
            {
                return $this->mappingSnapshot($context, $result);
            }

            protected function loggingEnabled(): bool
            {
                return $this->logging;
            }

            protected function strategyFor(CraftFieldInterface $craftField): Field
            {
                $this->strategyCalls++;

                return $this->strategy;
            }
        };
    }

    /**
     * A strategy recording every back-fill it is asked for, and stamping the owner
     * element onto the children as its "identity".
     */
    protected function spy(): Field
    {
        return new class() extends Field {
            /** @var list<array{element: ElementInterface, handle: string, children: list<ChildResult>}> */
            public array $calls = [];

            public function parse(FieldContext $context): mixed
            {
                return null;
            }

            public function attachSavedChildren(ElementInterface $element, string $handle, array $children): void
            {
                $this->calls[] = ['element' => $element, 'handle' => $handle, 'children' => $children];

                foreach ($children as $child) {
                    $child->element = $element;
                }
            }
        };
    }

    /**
     * A presenter recording the results it was handed, so the ORDER of the pass
     * and the presentation is assertable. Its label lookup is stubbed too: the
     * real one reads the target's mappable fields, which is no part of this spec.
     */
    protected function presenter(): ItemRowPresenter
    {
        return new class() extends ItemRowPresenter {
            /** @var list<MappingResult> */
            public array $presented = [];

            public function presentMappingResults(
                array $results,
                ElementInterface $element,
                array $labels = [],
                bool $withParsedHtml = false,
            ): array {
                $this->presented = $results;

                return [['presented' => true]];
            }

            public function fieldLabels(Link $link, ElementTargetInterface $target): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<ChildResult>|null $children
     */
    protected function row(string $handle, ?array $children = null): MappingResult
    {
        return new MappingResult(
            handle: $handle,
            node: $handle,
            default: null,
            native: false,
            rawValue: null,
            children: $children,
            childrenType: $children !== null ? 'blocks' : null,
        );
    }

    /**
     * @param list<MappingResult> $mappingResults
     */
    protected function outcome(ItemAction $action, ?ElementInterface $element, array $mappingResults): ItemSyncResult
    {
        return new ItemSyncResult(
            decision: SyncDecision::UPDATE,
            action: $action,
            matchValue: 'abc',
            element: $element,
            isNew: false,
            changed: true,
            mappingResults: $mappingResults,
        );
    }

    /**
     * An element whose layout exposes a craft field for the given handles only —
     * every other handle reads as a native attribute.
     *
     * @param list<string> $handles
     */
    protected function element(array $handles): ElementInterface
    {
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldByHandle')->willReturnCallback(
            function(string $handle) use ($handles): ?CraftFieldInterface {
                return in_array($handle, $handles, true)
                    ? $this->createMock(CraftFieldInterface::class)
                    : null;
            },
        );

        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);

        return $element;
    }

    protected function context(): SyncContext
    {
        return new SyncContext(
            link: FakeLink::make(),
            target: $this->createMock(ElementTargetInterface::class),
        );
    }
}
