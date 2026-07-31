<?php

namespace GlueAgency\Influx\Tests\unit\Support;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\BaseRelationField;
use craft\models\FieldLayout;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mixed into an anonymous Categories / Tags subclass so a spec can drive the
 * REAL flavour through {@see \GlueAgency\Influx\fields\GroupScopedRelation}'s
 * protected hooks. Stubs the two halves that need a booted Craft — the UID →
 * row-id lookup and the group service call — recording what the shared base
 * asked for.
 *
 * Pair it with {@see RelationCreateSpy} to drive a whole parse(): that one stubs
 * the element lookup, the element class and the create-time save.
 */
trait GroupScopedRelationSpy
{
    /** Group id the stubbed UID lookup resolves to; null = no such group here. */
    public ?int $groupId = 1;

    /** Layout the stubbed group lookup hands back. */
    public ?FieldLayout $layout = null;

    /** @var list<array{0: mixed, 1: string, 2: string}> source, prefix, table per lookup. */
    public array $resolved = [];

    /** @var list<string> UIDs the base asked a group layout for. */
    public array $layoutUids = [];

    /** @return list<FieldLayout> */
    public function exposedSourceFieldLayouts(BaseRelationField $field): array
    {
        return [...$this->sourceFieldLayouts($field)];
    }

    public function exposedScopeBySources(FieldContext $context, ElementQueryInterface $query): void
    {
        $this->scopeBySources($context, $query);
    }

    public function exposedCreateMissing(FieldContext $context, mixed $value): ?ElementInterface
    {
        return $this->createMissing($context, $value);
    }

    public function exposedShouldCreate(FieldContext $context): bool
    {
        return $this->shouldCreate($context);
    }

    protected function groupFieldLayout(string $uid): ?FieldLayout
    {
        $this->layoutUids[] = $uid;

        return $this->layout;
    }

    protected function sourceIdByUid(mixed $source, string $prefix, string $table): ?int
    {
        $this->resolved[] = [$source, $prefix, $table];

        return $this->sourceUid($source, $prefix) === null ? null : $this->groupId;
    }
}
