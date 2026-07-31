<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\RelationalField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\ChildResultCollector;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\item\SubMappingOutcome;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for what a relational strategy REPORTS about the related
 * elements it writes ({@see RelationalField::persistSubElement()}).
 *
 * The contract: every related element the mapping touches becomes a child of the
 * parent row — created / updated / unchanged — so the run log's drill-down shows
 * every element the feed addressed, not only the ones it rewrote. Two elements
 * are deliberately NOT reported: one that merely got linked (nothing happened to
 * it beyond the relation, which is the parent row's own business) and one whose
 * save was refused (that failure belongs on the parent row, not on a child
 * claiming the element was written).
 *
 * Reporting is real-run only: under dry run nothing is walked, saved or
 * reported at all — the debug inspector shows no relation children because
 * nothing is ever simulated against a real, saved element.
 *
 * Asserted through a real {@see ChildResultCollector} on the context, frame and
 * all, so the true channel is what's under test.
 */
class SubMappingChildCaptureTest extends Unit
{
    public function testAChangedRelatedElementIsReportedAsAnUpdatedChild(): void
    {
        [$strategy, $applier, $children] = $this->capture();

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertCount(1, $children);
        $this->assertSame(ChildAction::UPDATED->value, $children[0]->action);
        $this->assertSame('Draft title', $children[0]->title);
        $this->assertSame($strategy->sub, $children[0]->element);
        $this->assertSame($strategy->sub, $children[0]->labelElement);
        $this->assertSame($applier->rows, $children[0]->mappingResults);
    }

    public function testAnUnchangedRelatedElementIsStillReportedButNeverSaved(): void
    {
        [$strategy, $applier, $children] = $this->capture(subMappingChanged: false);

        $this->assertSame(0, $strategy->saveCalls);
        $this->assertCount(1, $children);
        $this->assertSame(ChildAction::UNCHANGED->value, $children[0]->action);
        $this->assertSame($applier->rows, $children[0]->mappingResults);
    }

    public function testACreatedRelatedElementIsReportedAsCreatedEvenWhenSubMappingsWroteToIt(): void
    {
        [$strategy, $applier, $children] = $this->capture(created: true);

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertCount(1, $children);
        $this->assertSame(ChildAction::CREATED->value, $children[0]->action);
        $this->assertSame($applier->rows, $children[0]->mappingResults);
    }

    public function testACreatedRelatedElementWithoutSubMappingsIsReportedWithNoRows(): void
    {
        [$strategy, , $children] = $this->capture(mappingConfig: ['node' => 'ref'], created: true);

        $this->assertSame(0, $strategy->saveCalls);
        $this->assertCount(1, $children);
        $this->assertSame(ChildAction::CREATED->value, $children[0]->action);
        $this->assertSame([], $children[0]->mappingResults);
    }

    public function testAMerelyLinkedRelatedElementIsNotReportedAtAll(): void
    {
        [$strategy, , $children] = $this->capture(mappingConfig: ['node' => 'ref']);

        $this->assertSame(0, $strategy->saveCalls);
        $this->assertNull($children);
    }

    public function testADryRunReportsNothingAndNeverWalksTheSubMappings(): void
    {
        [$strategy, $applier, $children] = $this->capture(created: true, dryRun: true);

        $this->assertSame(0, $applier->walkCalls);
        $this->assertSame(0, $strategy->saveCalls);
        $this->assertNull($children);
    }

    public function testARefusedSaveThrowsAndReportsNoChild(): void
    {
        $strategy = $this->relationalStrategy($this->subElement(), false);
        $applier = $this->applier(true);
        $collector = new ChildResultCollector();
        $context = $this->context($applier, $collector);

        $collector->open();

        try {
            $strategy->run($context);
            $this->fail('A refused related-element save must throw.');
        } catch (MappingValueException $e) {
            $this->assertStringContainsString('#42', $e->getMessage());
        }

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertNull($collector->close());
    }

    /**
     * A context nobody lent a collector to (a strategy exercised directly) still
     * persists; the reporting half is simply skipped.
     */
    public function testAContextWithoutACollectorStillPersists(): void
    {
        [$strategy, , $children] = $this->capture(collects: false);

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertNull($children);
    }

    /**
     * Run one related-element persist inside an open collector frame and hand
     * back what the frame collected (null when nothing was reported, or when the
     * context carries no collector at all).
     *
     * @param array<string, mixed>|null $mappingConfig
     * @return array{0: RelationalField, 1: MappingApplier, 2: list<ChildResult>|null}
     */
    protected function capture(
        ?array $mappingConfig = null,
        bool $subMappingChanged = true,
        bool $saveResult = true,
        bool $created = false,
        bool $dryRun = false,
        bool $collects = true,
    ): array {
        $strategy = $this->relationalStrategy($this->subElement(), $saveResult);
        $applier = $this->applier($subMappingChanged);
        $collector = $collects ? new ChildResultCollector() : null;
        $context = $this->context($applier, $collector, $mappingConfig, $dryRun);

        $collector?->open();
        $strategy->run($context, $created);

        return [$strategy, $applier, $collector?->close()];
    }

    /**
     * The context a top-level relational mapping walk would hand the strategy:
     * a mapping carrying sub-mappings (unless overridden), the walk's applier and
     * its collector.
     *
     * @param array<string, mixed>|null $mappingConfig
     */
    protected function context(
        MappingApplier $applier,
        ?ChildResultCollector $collector,
        ?array $mappingConfig = null,
        bool $dryRun = false,
    ): FieldContext {
        return new FieldContext(
            craftField: null,
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', $mappingConfig ?? [
                'node'         => 'ref',
                'nativeFields' => ['title' => ['node' => 'name']],
            ]),
            item: new RemoteItem(['ref' => 'abc', 'name' => 'Draft title']),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            dryRun: $dryRun,
            applier: $applier,
            childCollector: $collector,
        );
    }

    /**
     * The related element the feed also fills: it reports an id, a UI label and
     * the validation error a refused save has to name.
     */
    protected function subElement(): Element
    {
        $sub = $this->createMock(Element::class);
        $sub->method('getUiLabel')->willReturn('Draft title');
        $sub->method('getFirstErrors')->willReturn(['title' => 'Title cannot be blank.']);
        $sub->id = 42;

        return $sub;
    }

    /**
     * A relational strategy that runs the real persistSubElement() against a
     * stubbed save seam, counting the calls. `run()` drives the persist directly
     * — parse() is the flavours' business, and each passes its own
     * created-or-matched verdict down.
     */
    protected function relationalStrategy(ElementInterface $sub, bool $saveResult): RelationalField
    {
        return new class($sub, $saveResult) extends RelationalField {
            public ElementInterface $sub;

            public bool $saveResult = true;

            public int $saveCalls = 0;

            public function __construct(ElementInterface $sub, bool $saveResult)
            {
                $this->sub = $sub;
                $this->saveResult = $saveResult;
            }

            public function run(FieldContext $context, bool $created = false): void
            {
                $this->persistSubElement($context, $this->sub, $created);
            }

            /** Required by the base; this spec drives persistSubElement() directly. */
            public function parse(FieldContext $context): mixed
            {
                return null;
            }

            protected function saveSubElement(ElementInterface $element): bool
            {
                $this->saveCalls++;

                return $this->saveResult;
            }
        };
    }

    /**
     * A real applier with its sub-mapping walk stubbed to a fixed outcome — the
     * walk itself is specced elsewhere; what matters here is the rows it hands
     * back and whether they report a change. The row is present either way: a
     * walk that ran always produced rows, and an unchanged child must carry them
     * too.
     */
    protected function applier(bool $subMappingChanged): MappingApplier
    {
        return new class($subMappingChanged) extends MappingApplier {
            /** @var list<MappingResult> */
            public array $rows = [];

            public int $walkCalls = 0;

            public function __construct(bool $subMappingChanged)
            {
                parent::__construct();

                $this->rows = [
                    new MappingResult(
                        handle: 'title',
                        node: 'name',
                        default: null,
                        native: true,
                        rawValue: 'Draft title',
                        changed: $subMappingChanged,
                    ),
                ];
            }

            public function applySubMappings(FieldContext $parentContext, ElementInterface $element): SubMappingOutcome
            {
                $this->walkCalls++;

                return new SubMappingOutcome($this->rows);
            }
        };
    }
}
