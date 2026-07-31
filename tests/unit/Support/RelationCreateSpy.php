<?php

namespace GlueAgency\Influx\Tests\unit\Support;

use craft\base\ElementInterface;
use GlueAgency\Influx\sync\FieldContext;
use yii\base\Model;

/**
 * Mixed into an anonymous subclass of a REAL relation flavour so a spec can
 * drive {@see \GlueAgency\Influx\fields\Relation::parse()} end to end without a
 * booted Craft: the element lookup, the element class the flavour instantiates,
 * and the create-time save are the three halves that would otherwise reach for
 * `Craft::$app`.
 *
 * The save stub is the interesting one — {@see $saveError} makes it REFUSE the
 * way Craft does for a taken title: false plus a validation error on the
 * element, no exception. That is precisely the shape that used to resolve to
 * "no element" and detach an entry's relations.
 */
trait RelationCreateSpy
{
    /** Element class the flavour builds; null = the real Craft one. */
    public ?string $elementClass = FakeElement::class;

    /** @var array<string, ElementInterface> Elements the stubbed lookup resolves, keyed by reference value. */
    public array $existing = [];

    /** @var list<mixed> Reference values the stubbed lookup was asked for. */
    public array $lookedUp = [];

    /** Validation error the stubbed save refuses with; null = it accepts. */
    public ?string $saveError = null;

    /** @var list<ElementInterface> Elements handed to the stubbed save, in order. */
    public array $saved = [];

    /** Id the stubbed save stamps on the next element it accepts, as Craft would. */
    public int $nextId = 100;

    protected function elementType(): string
    {
        return $this->elementClass ?? parent::elementType();
    }

    protected function findOne(FieldContext $context, string $match, mixed $value): ?ElementInterface
    {
        $this->lookedUp[] = $value;

        return $this->existing[(string) $value] ?? null;
    }

    protected function saveNewElement(ElementInterface $element): bool
    {
        $this->saved[] = $element;

        if ($this->saveError !== null) {
            if ($element instanceof Model) {
                $element->addError('title', $this->saveError);
            }

            return false;
        }

        $element->id ??= $this->nextId++;

        return true;
    }
}
