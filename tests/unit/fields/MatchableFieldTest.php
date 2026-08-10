<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use GlueAgency\Influx\fields\Addresses;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Color;
use GlueAgency\Influx\fields\ContentBlock;
use GlueAgency\Influx\fields\Country;
use GlueAgency\Influx\fields\Date;
use GlueAgency\Influx\fields\DefaultField;
use GlueAgency\Influx\fields\Dropdown;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\fields\Json;
use GlueAgency\Influx\fields\Lightswitch;
use GlueAgency\Influx\fields\Link;
use GlueAgency\Influx\fields\Matrix;
use GlueAgency\Influx\fields\RichText;
use GlueAgency\Influx\fields\Table;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\fields\Users;

/**
 * {@see Field::matchable()} — which field types may be offered as a link's match
 * attribute.
 *
 * Matching runs the stored value straight at an element query, so it only means
 * anything for a field holding one comparable value a feed can carry as an
 * external identifier. The list below is the whole rule, pinned per type because
 * a wrong answer is silent either way: too permissive offers a key that matches
 * nothing, too strict hides a usable one.
 */
class MatchableFieldTest extends Unit
{
    /**
     * A relation stores ids of OTHER elements, so the only feed value that could
     * ever match is Craft's own id for the related element. Note this is not a
     * failing query — BaseRelationField builds a valid relation condition — which
     * is exactly why it had to be excluded explicitly.
     */
    public function testRelationFieldsAreNotMatchable(): void
    {
        foreach ([Assets::class, Entries::class, Users::class, Categories::class, Tags::class] as $strategy) {
            $this->assertFalse($strategy::matchable(), "{$strategy} must not be offered as a match key.");
        }
    }

    public function testStructuralFieldsAreNotMatchable(): void
    {
        // Nested elements, row sets and structured values: nothing a single feed
        // value identifies an element by.
        foreach ([Matrix::class, Table::class, Addresses::class, ContentBlock::class, Json::class, Link::class] as $strategy) {
            $this->assertFalse($strategy::matchable(), "{$strategy} must not be offered as a match key.");
        }
    }

    public function testSingleValueFieldsStayMatchable(): void
    {
        foreach ([
            DefaultField::class,
            Dropdown::class,
            Date::class,
            Lightswitch::class,
            Country::class,
            Color::class,
            RichText::class,
        ] as $strategy) {
            $this->assertTrue($strategy::matchable(), "{$strategy} should stay available as a match key.");
        }
    }

    public function testTheBaseDefaultIsPermissive(): void
    {
        // A field type Influx has no strategy for lands on DefaultField, and is
        // usually a plain value — refusing it by default would silently narrow the
        // option list for every third-party field.
        $this->assertTrue(DefaultField::matchable());
    }
}
