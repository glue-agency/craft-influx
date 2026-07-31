<?php

namespace GlueAgency\Influx\sync\item;

/**
 * Carries the {@see ChildResult}s a field strategy reports out of a mapping walk
 * whose {@see MappingResult} is only built afterwards. A strategy sits deep
 * inside parse()/apply() with no row to attach to yet, so it pushes children
 * onto the frame the walk opened and
 * {@see MappingApplier::mapCustomField()} closes that frame for the row it is
 * about to build.
 *
 * A stack of frames, not one flat list, because the walk re-enters
 * mapCustomField() for every sub-mapping: a nested walk opens a frame of its
 * own, so its children attach to the level that opened it instead of leaking
 * into the parent row.
 *
 * One collector lives per item walk, riding the {@see \GlueAgency\Influx\sync\FieldContext}.
 */
class ChildResultCollector
{
    /**
     * The open frames, innermost last.
     *
     * @var list<list<ChildResult>>
     */
    protected array $frames = [];

    /**
     * Start collecting: a mapping walk that may report children is beginning.
     */
    public function open(): void
    {
        $this->frames[] = [];
    }

    /**
     * Report one child to the innermost open frame. A no-op when no frame is
     * open — a strategy exercised outside a collecting walk (directly, in a
     * test) must still run rather than error.
     */
    public function add(ChildResult $child): void
    {
        if ($this->frames === []) {
            return;
        }

        $this->frames[count($this->frames) - 1][] = $child;
    }

    /**
     * Close the innermost frame and hand back what it collected, or null when it
     * collected nothing — a row's children stay null when the mapping nests
     * nothing. Null too when no frame was open, so an unbalanced close can't
     * take the walk down.
     *
     * @return list<ChildResult>|null
     */
    public function close(): ?array
    {
        if ($this->frames === []) {
            return null;
        }

        $children = array_pop($this->frames);

        return $children !== [] ? $children : null;
    }
}
