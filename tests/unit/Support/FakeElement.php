<?php

namespace GlueAgency\Influx\Tests\unit\Support;

use craft\base\Element;

/**
 * A real Craft element the bootless unit suite can actually instantiate:
 * {@see Element::init()} asks `Craft::$app` whether the install exists and
 * {@see Element::behaviors()} wires up the runtime-generated
 * CustomFieldBehavior — neither of which exists without a booted app, so both
 * are neutralised here. Everything else (attributes, the yii\base\Model error
 * API) is the genuine article.
 *
 * Enough element for the creation paths under test: a relation strategy builds
 * one, stamps where it belongs plus a title, hands it to the save seam, and
 * reads the validation errors back off a refusal.
 */
class FakeElement extends Element
{
    /** Stamped by the group-scoped flavours ({@see \GlueAgency\Influx\fields\Tags} / Categories). */
    public ?int $groupId = null;

    /** Stamped by {@see \GlueAgency\Influx\fields\Entries}. */
    public ?int $sectionId = null;

    /** Stamped by {@see \GlueAgency\Influx\fields\Entries}. */
    public ?int $typeId = null;

    public function init(): void
    {
    }

    public function behaviors(): array
    {
        return [];
    }
}
