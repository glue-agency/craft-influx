<?php

namespace GlueAgency\Influx\Tests\unit\sync\item;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ChildAction;
use GlueAgency\Influx\sync\item\ChildResult;
use GlueAgency\Influx\sync\item\MappingResult;
use GlueAgency\Influx\sync\item\ValidationErrorRouter;
use GlueAgency\Influx\Tests\unit\Support\FakeElement;

/**
 * Where a refused save's validation errors end up.
 *
 * Craft reports them keyed by attribute path — `test_email` for the element's own
 * field, `test_matrix[new1].title` for a field on a nested one — and nothing read
 * those keys: every message was JSON-encoded into the item's message, so the
 * operator read a blob naming fields while the rows beneath it, which say exactly
 * which node each value came from, showed nothing at all.
 *
 * Real case, from parcours log #6:
 * `{"test_matrix[new1].title":["Title cannot be blank."],"test_super_table[new1].title":["Title cannot be blank."]}`
 */
class ValidationErrorRoutingTest extends Unit
{
    public function testAPlainFieldsErrorLandsOnItsOwnRow(): void
    {
        $rows = [$this->row('test_email')];

        $unclaimed = $this->route(['test_email' => ['Email is not a valid email address.']], $rows);

        $this->assertSame('Email is not a valid email address.', $rows[0]->error);
        $this->assertSame([], $unclaimed);
    }

    public function testANestedErrorLandsOnTheChildsOwnLeafRow(): void
    {
        $leaf = $this->row('title');
        $rows = [$this->row('test_matrix', [$this->child('Tekst', ChildAction::CREATED, [$leaf])])];

        $this->route(['test_matrix[new1].title' => ['Title cannot be blank.']], $rows);

        $this->assertSame('Title cannot be blank.', $leaf->error);
    }

    public function testTheParentRowSaysItToo(): void
    {
        // The drill row is collapsed by default; without this it reads clean
        // while something inside it refused to save. It names the child, so a
        // reader knows which one before drilling.
        $leaf = $this->row('title');
        $parent = $this->row('test_matrix', [$this->child('Tekst', ChildAction::CREATED, [$leaf])]);

        $this->route(['test_matrix[new1].title' => ['Title cannot be blank.']], [$parent]);

        $this->assertSame('Tekst: Title cannot be blank.', $parent->error);
    }

    public function testASavedChildIsMatchedByIdAndANewOneByPosition(): void
    {
        // Craft keys a saved nested element by its id and a new one by the order
        // it created them in.
        $existing = $this->row('title');
        $firstNew = $this->row('title');
        $secondNew = $this->row('title');

        $parent = $this->row('test_matrix', [
            $this->child('Bestaand', ChildAction::UPDATED, [$existing], 4012),
            $this->child('Eerste', ChildAction::CREATED, [$firstNew]),
            $this->child('Tweede', ChildAction::CREATED, [$secondNew]),
        ]);

        $this->route([
            'test_matrix[4012].title' => ['Stored one refused.'],
            'test_matrix[new2].title' => ['Second new one refused.'],
        ], [$parent]);

        $this->assertSame('Stored one refused.', $existing->error);
        $this->assertSame('Second new one refused.', $secondNew->error);
        $this->assertNull($firstNew->error, 'new1 is a different block from new2.');
    }

    public function testAnUnidentifiableChildStillReachesTheParentRow(): void
    {
        // Better a message on the row that owns the field than a message nowhere.
        $parent = $this->row('test_matrix', [$this->child('Tekst', ChildAction::UNCHANGED, [$this->row('title')])]);

        $this->route(['test_matrix[new1].title' => ['Title cannot be blank.']], [$parent]);

        $this->assertSame('(title): Title cannot be blank.', $parent->error);
    }

    public function testAnErrorForAnUnmappedFieldIsHandedBack(): void
    {
        // No row will ever mention it, so the item's message has to keep saying
        // it — that's what the return value is for.
        $rows = [$this->row('test_email')];

        $unclaimed = $this->route([
            'test_email'        => ['Email is not a valid email address.'],
            'someRequiredField' => ['Cannot be blank.'],
        ], $rows);

        $this->assertSame(['someRequiredField' => ['Cannot be blank.']], $unclaimed);
    }

    public function testAStrategyErrorOnTheSameRowSurvives(): void
    {
        // A strategy that threw and a field Craft refused are two different
        // things about one row, and the operator needs both.
        $row = $this->row('test_email');
        $row->error = 'The strategy threw.';

        $this->route(['test_email' => ['Email is not a valid email address.']], [$row]);

        $this->assertSame('The strategy threw. Email is not a valid email address.', $row->error);
    }

    public function testTheSameMessageIsNotRepeated(): void
    {
        $row = $this->row('test_email');

        $this->route(['test_email' => ['Twice.', 'Twice.']], [$row]);

        $this->assertSame('Twice.', $row->error);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param list<MappingResult> $rows
     * @return array<string, list<string>>
     */
    protected function route(array $errors, array $rows): array
    {
        return (new ValidationErrorRouter())->route($errors, $rows);
    }

    /** @param list<ChildResult>|null $children */
    protected function row(string $handle, ?array $children = null): MappingResult
    {
        return new MappingResult(
            handle: $handle,
            node: null,
            default: null,
            native: false,
            rawValue: null,
            children: $children,
        );
    }

    /** @param list<MappingResult> $mappingResults */
    protected function child(string $title, ChildAction $action, array $mappingResults, ?int $elementId = null): ChildResult
    {
        return new ChildResult(
            title: $title,
            element: $elementId !== null ? $this->element($elementId) : null,
            action: $action->value,
            mappingResults: $mappingResults,
        );
    }

    protected function element(int $id): ElementInterface
    {
        $element = new FakeElement();
        $element->id = $id;

        return $element;
    }
}
