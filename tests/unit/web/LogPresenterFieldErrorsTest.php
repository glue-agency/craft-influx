<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\web\LogPresenter;

/**
 * Behaviour spec for {@see LogPresenter}'s field-error helpers, which back the
 * log-item drill-down: fieldErrors() decodes the stored per-field-error JSON,
 * and overlayFieldErrors() stamps those (authoritative, run-time) errors back
 * onto the dry-run mapping rows. Pure — no Craft boot needed, since the JSON
 * decode + row overlay touch nothing but the passed arrays.
 */
class LogPresenterFieldErrorsTest extends Unit
{
    protected LogPresenter $presenter;

    protected function _before(): void
    {
        $this->presenter = new LogPresenter();
    }

    public function testFieldErrorsDecodesValidJson(): void
    {
        $errors = $this->presenter->fieldErrors('{"summary":"Too long","body":"Bad HTML"}');

        $this->assertSame(['summary' => 'Too long', 'body' => 'Bad HTML'], $errors);
    }

    public function testFieldErrorsReturnsEmptyForNull(): void
    {
        $this->assertSame([], $this->presenter->fieldErrors(null));
    }

    public function testFieldErrorsReturnsEmptyForEmptyString(): void
    {
        $this->assertSame([], $this->presenter->fieldErrors(''));
    }

    public function testFieldErrorsReturnsEmptyForInvalidJson(): void
    {
        $this->assertSame([], $this->presenter->fieldErrors('{not json'));
    }

    public function testFieldErrorsReturnsEmptyForNonObjectJson(): void
    {
        // A bare scalar decodes to a non-array; treated as "no errors".
        $this->assertSame([], $this->presenter->fieldErrors('42'));
    }

    public function testOverlayLandsErrorOnMatchingHandleRow(): void
    {
        $mappings = [
            ['handle' => 'summary', 'error' => null],
            ['handle' => 'body', 'error' => null],
        ];

        $result = $this->presenter->overlayFieldErrors($mappings, ['body' => 'Bad HTML']);

        $this->assertNull($result[0]['error']);
        $this->assertSame('Bad HTML', $result[1]['error']);
    }

    public function testOverlayLeavesNonMatchingRowsUntouched(): void
    {
        $mappings = [
            ['handle' => 'summary', 'error' => 'existing'],
        ];

        // Error keyed to a handle that isn't in the rows → nothing changes.
        $result = $this->presenter->overlayFieldErrors($mappings, ['title' => 'Nope']);

        $this->assertSame($mappings, $result);
    }

    public function testOverlayWithEmptyErrorsReturnsMappingsUnchanged(): void
    {
        $mappings = [
            ['handle' => 'summary', 'error' => null],
        ];

        $this->assertSame($mappings, $this->presenter->overlayFieldErrors($mappings, []));
    }
}
