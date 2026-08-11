<?php

namespace GlueAgency\Influx\web;

use craft\base\Chippable;
use craft\base\Grippable;

/**
 * Presents a bare VALUE to Craft's chip renderer — a run's trigger, the
 * partial-import preset it applied — so a fact that isn't a component still
 * reads like one in a CP table, alongside the link and site chips it sits next
 * to. {@see LinkChip} is the same adapter for a real config model; this one
 * stands in where there is no model at all, only a label.
 *
 * The chip is deliberately inert: no id, so nothing addresses it; no
 * `CpEditable`, so the label never hyperlinks; and callers pass
 * `autoReload: false`, so Craft's chip JS never asks the server to re-render
 * something the server can't look up. {@see get()} answers null for the same
 * reason — the contract is "this value has no component behind it", not "the
 * lookup failed".
 *
 * `Grippable` is implemented so a caller CAN put a second line under the label
 * (Craft's `smalltext light code` styling) — a value plus what it's a value of.
 * Passing no handle leaves the chip single-line.
 *
 * Craft 5 ONLY, exactly like {@see LinkChip}: `Chippable` arrived in 5.0, so
 * this class is only ever reached from the guarded branch in
 * {@see \GlueAgency\Influx\helpers\Compat::valueChipHtml()}, which falls back to
 * the gray pill on Craft 4. Nothing else may reference it unguarded.
 *
 * Treat as read-only: a chip describes one value and is built per render.
 */
class ValueChip implements Chippable, Grippable
{
    /**
     * The label the chip carries.
     */
    protected string $label;

    /**
     * Optional second line under the label, or null for a single-line chip.
     */
    protected ?string $handle;

    public function __construct(string $label, ?string $handle = null)
    {
        $this->label = $label;
        $this->handle = $handle;
    }

    /**
     * @inheritdoc
     */
    public static function get(string|int $id): ?static
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getId(): ?int
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getUiLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): ?string
    {
        return $this->handle;
    }
}
