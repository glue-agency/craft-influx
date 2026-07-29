<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\RelationalField;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\MappingApplier;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for a REFUSED related-element save
 * ({@see RelationalField::persistSubElement()}).
 *
 * The contract: `saveElement()` returning false is a failure, not a success —
 * the discipline the missing-elements sweeper already holds itself to. A
 * sub-element has no log row of its own, so the failure has to surface on the
 * PARENT mapping's row ({@see MappingApplier::applyCustomField()} is the only
 * catch in the walk); discarding the return let the parent row report success
 * while the related element silently kept its old values.
 *
 * Also pins the strategy-resolution seam: the walk resolves its strategy
 * through the resolver on the {@see FieldContext}, so this whole spec runs
 * without a booted plugin behind it.
 */
class SubElementSaveErrorTest extends Unit
{
    public function testARefusedSubElementSaveLandsAsAnErrorOnTheParentRow(): void
    {
        [$strategy, $result] = $this->applyRow(saveResult: false);

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('#42', $result->error);
        $this->assertStringContainsString("'Draft title'", $result->error);
        $this->assertStringContainsString('title: Title cannot be blank.', $result->error);
        $this->assertSame('related', $result->handle);
    }

    public function testAnAcceptedSubElementSaveLeavesTheParentRowClean(): void
    {
        [$strategy, $result] = $this->applyRow(saveResult: true);

        $this->assertSame(1, $strategy->saveCalls);
        $this->assertNull($result->error);
        $this->assertSame([42], $result->parsedValue);
        $this->assertTrue($result->changed);
    }

    public function testAnUnchangedSubElementIsNeverSavedAtAll(): void
    {
        [$strategy, $result] = $this->applyRow(saveResult: false, subMappingChanged: false);

        $this->assertSame(0, $strategy->saveCalls);
        $this->assertNull($result->error);
    }

    public function testAMappingWithoutSubMappingsNeverReachesTheSave(): void
    {
        [$strategy, $result] = $this->applyRow(saveResult: false, mappingConfig: ['node' => 'ref']);

        $this->assertSame(0, $strategy->saveCalls);
        $this->assertNull($result->error);
    }

    /**
     * One top-level relational mapping row, walked by a real
     * {@see MappingApplier} whose strategy resolution and sub-mapping walk are
     * both stubbed through the context's seams.
     *
     * @param array<string, mixed> $mappingConfig
     * @return array{0: RelationalField, 1: MappingResult}
     */
    protected function applyRow(bool $saveResult, bool $subMappingChanged = true, ?array $mappingConfig = null): array
    {
        $strategy = $this->relationalStrategy($this->subElement(), $saveResult);
        $applier = $this->applier($strategy, $subMappingChanged);

        $context = new FieldContext(
            craftField: $this->createMock(CraftFieldInterface::class),
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', $mappingConfig ?? [
                'node'         => 'ref',
                'nativeFields' => ['title' => ['node' => 'name']],
            ]),
            item: new RemoteItem(['ref' => 'abc', 'name' => 'Draft title']),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            strategyResolver: static fn(CraftFieldInterface $field): Field => $strategy,
            applier: $applier,
        );

        return [$strategy, $applier->row($context)];
    }

    /**
     * The related element the feed also fills: it reports an id, a UI label and
     * the validation error that made its save fail, so the message can name all
     * three.
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
     * stubbed save seam, counting the calls.
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

            public function parse(FieldContext $context): mixed
            {
                $this->persistSubElement($context, $this->sub);

                return [$this->sub->id];
            }

            protected function saveSubElement(ElementInterface $element): bool
            {
                $this->saveCalls++;

                return $this->saveResult;
            }
        };
    }

    /**
     * A real applier with applyCustomField() exposed (the top-level row path,
     * error catch included) and the sub-mapping walk stubbed to a verdict — the
     * walk itself is specced elsewhere; what matters here is whether it reports
     * a change.
     */
    protected function applier(Field $strategy, bool $subMappingChanged): MappingApplier
    {
        return new class($strategy, $subMappingChanged) extends MappingApplier {
            public bool $subMappingChanged = false;

            public function __construct(Field $strategy, bool $subMappingChanged)
            {
                parent::__construct(static fn(CraftFieldInterface $field): Field => $strategy);
                $this->subMappingChanged = $subMappingChanged;
            }

            public function row(FieldContext $context): MappingResult
            {
                return $this->applyCustomField($context);
            }

            public function applySubMappings(FieldContext $parentContext, ElementInterface $element): bool
            {
                return $this->subMappingChanged;
            }
        };
    }
}
