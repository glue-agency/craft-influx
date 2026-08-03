<?php

namespace GlueAgency\Influx\sync\item;

use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ChildAction;

/**
 * Routes an element's validation errors onto the mapping rows they belong to.
 *
 * Craft reports them keyed by attribute path — `test_email` for the element's
 * own field, `test_matrix[new1].title` for a field on a nested element — and
 * until now nothing read those keys: every message ended up JSON-encoded in the
 * item's `message` ({@see ItemProcessor::commitFailureMessage()}), so an operator
 * read a blob naming fields the rows beneath it said nothing about. The rows
 * already carry an error channel that both inspectors render
 * ({@see MappingResult::$error}), so this only has to put the message where it
 * happened.
 *
 * A nested key is attributed twice on purpose: to the child's own leaf row, which
 * is where the value came from, AND to the parent row, because that row is
 * collapsed by default and would otherwise look clean while something inside it
 * refused to save.
 */
class ValidationErrorRouter
{
    /**
     * @param array<string, list<string>|string> $errors As `$element->getErrors()`
     * reports them.
     * @param list<MappingResult> $rows The item's top-level rows, mutated in place.
     * @return array<string, list<string>> The errors NO row claimed, keyed as
     * Craft reported them — a required field this link doesn't map, a title Craft
     * generates. Nothing in the drill-down will mention those, so the caller has
     * to keep saying them itself.
     */
    public function route(array $errors, array $rows): array
    {
        $unclaimed = [];
        $byHandle = [];

        foreach ($rows as $row) {
            $byHandle[$row->handle] = $row;
        }

        foreach ($errors as $key => $messages) {
            $messages = array_values(array_filter(array_map('strval', (array) $messages)));

            if ($messages === []) {
                continue;
            }

            [$handle, $childKey, $attribute] = $this->parseKey((string) $key);
            $row = $byHandle[$handle] ?? null;

            if ($row === null) {
                $unclaimed[(string) $key] = $messages;

                continue;
            }

            if ($childKey === null) {
                $this->append($row, $messages);

                continue;
            }

            $child = $this->childFor($row, $childKey);
            $leaf = $child !== null && $attribute !== null ? $this->leafFor($child, $attribute) : null;

            if ($leaf !== null) {
                $this->append($leaf, $messages);
            }

            $this->append($row, $this->attributed($child, $leaf === null ? $attribute : null, $messages));
        }

        return $unclaimed;
    }

    /**
     * Split `field[child].attribute` into its three parts, either of the last two
     * being null for a plain `field`.
     *
     * Only the FIRST bracketed segment is read as the child. Craft can nest
     * deeper (a block inside a block), and the remainder is then kept whole as
     * the attribute so it still reaches the parent row verbatim rather than being
     * mis-attributed to a leaf that doesn't exist.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    protected function parseKey(string $key): array
    {
        if (! preg_match('/^([^\[.]+)(?:\[([^\]]*)\])?(?:\.(.+))?$/', $key, $matches)) {
            return [$key, null, null];
        }

        return [
            $matches[1],
            ($matches[2] ?? '') !== '' ? $matches[2] : null,
            ($matches[3] ?? '') !== '' ? $matches[3] : null,
        ];
    }

    /**
     * The child a bracketed key names.
     *
     * Craft keys a SAVED nested element by its id and a new one by position —
     * `new1` is the first one it created in this save, `new2` the second — so
     * both are matched on their own terms: by id where the child has one, else by
     * counting the children this run created in order. A child that can't be
     * identified is no child; the message then lands on the parent row alone.
     */
    protected function childFor(MappingResult $row, string $childKey): ?ChildResult
    {
        $children = $row->children ?? [];

        if (ctype_digit($childKey)) {
            foreach ($children as $child) {
                if ($this->idOf($child->element) === (int) $childKey) {
                    return $child;
                }
            }

            return null;
        }

        if (! preg_match('/^new(\d+)$/', $childKey, $matches)) {
            return null;
        }

        $wanted = (int) $matches[1];
        $seen = 0;

        foreach ($children as $child) {
            if (! $this->isNew($child)) {
                continue;
            }

            if (++$seen === $wanted) {
                return $child;
            }
        }

        return null;
    }

    /** The child's own row for an attribute, when it mapped one. */
    protected function leafFor(ChildResult $child, string $attribute): ?MappingResult
    {
        foreach ($child->mappingResults as $leaf) {
            if ($leaf->handle === $attribute) {
                return $leaf;
            }
        }

        return null;
    }

    /**
     * The parent row's copy of a nested message: named after the child it came
     * from, so a reader who hasn't drilled in knows which one refused — and after
     * the attribute too when no leaf row claimed it.
     *
     * @param list<string> $messages
     * @return list<string>
     */
    protected function attributed(?ChildResult $child, ?string $attribute, array $messages): array
    {
        $prefix = trim(($child?->title ?? '') . ($attribute !== null ? " ({$attribute})" : ''));

        if ($prefix === '') {
            return $messages;
        }

        return array_map(static fn(string $message): string => "{$prefix}: {$message}", $messages);
    }

    /**
     * Whether this run created the child — the population Craft's `newN` keys
     * count through. Both the committed action and the dry-run label count, so
     * the reading doesn't depend on which pass produced the rows.
     */
    protected function isNew(ChildResult $child): bool
    {
        return in_array($child->action, [
            ChildAction::CREATED->value,
            ChildAction::CREATED->dryRunLabel(),
            ChildAction::ADDED->value,
            ChildAction::ADDED->dryRunLabel(),
        ], true);
    }

    protected function idOf(?ElementInterface $element): ?int
    {
        return $element?->id !== null ? (int) $element->id : null;
    }

    /**
     * Add to a row's error channel without losing what's there: a strategy that
     * threw AND a field Craft refused are two different things about the same
     * row, and the operator needs both.
     *
     * @param list<string> $messages
     */
    protected function append(MappingResult $row, array $messages): void
    {
        $existing = $row->error !== null ? [$row->error] : [];

        $row->error = implode(' ', array_unique(array_merge($existing, $messages)));
    }
}
