<?php

namespace GlueAgency\Influx\web;

use craft\base\Chippable;
use craft\base\CpEditable;
use craft\base\Grippable;
use craft\helpers\UrlHelper;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;

/**
 * Presents a {@see Link} to Craft's chip renderer, so a link can be drawn as a
 * CP chip the same way Craft draws its own config models (Section, EntryType),
 * which are Chippable for exactly this reason.
 *
 * Craft 5 ONLY: `Chippable` and friends arrived in 5.0. The class is referenced
 * from a single guarded branch in {@see \GlueAgency\Influx\helpers\Compat::linkChipHtml()},
 * so on Craft 4 it is never autoloaded and its missing interfaces never matter.
 * Nothing else may reference it unguarded.
 *
 * An adapter rather than interfaces on the model itself, for the same reason:
 * `Link implements Chippable` would fatal the moment Craft 4 loaded the model.
 *
 * Implements:
 *  - `Chippable` — the label (the link's name) and identity the chip is built on;
 *  - `Grippable` — makes `showHandle` available, which puts the handle under the
 *    label in Craft's own `smalltext light code` styling;
 *  - `CpEditable` — makes `hyperlink` available, pointing the label at the link
 *    builder.
 *
 * Deliberately NOT `Statusable`: a link's run status is a property of its last
 * run, which the overviews report in a column of their own.
 */
class LinkChip implements Chippable, CpEditable, Grippable
{
    /**
     * The link being presented.
     */
    protected Link $link;

    public function __construct(Link $link)
    {
        $this->link = $link;
    }

    /**
     * @inheritdoc
     */
    public static function get(string|int $id): ?static
    {
        $link = Influx::getInstance()->links->getLinkById((int) $id);

        return $link ? new static($link) : null;
    }

    /**
     * @inheritdoc
     */
    public function getId(): ?int
    {
        return $this->link->id;
    }

    /**
     * @inheritdoc
     */
    public function getUiLabel(): string
    {
        return $this->link->name;
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): ?string
    {
        return $this->link->handle;
    }

    /**
     * @inheritdoc
     */
    public function getCpEditUrl(): ?string
    {
        return $this->link->id ? UrlHelper::cpUrl('influx/links/' . $this->link->id) : null;
    }
}
