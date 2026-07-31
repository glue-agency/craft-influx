<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use GlueAgency\Influx\web\LogPresenter;

/**
 * Behaviour spec for {@see LogPresenter::fieldErrors()}, which decodes a log
 * item's stored per-field-error JSON — the count the item list flags an item by
 * ({@see LogPresenter::presentItem()}'s `errorCount`). Pure — no Craft boot
 * needed, since the decode touches nothing but the passed string.
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
}
