<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\FieldMapping;

/**
 * Behaviour spec for FieldMapping::isActive() — THE distinction the sync walker
 * hangs the empty-clear policy on: an "active" mapping (a source node, or an
 * explicit "— use default —") is authoritative and clears the field when its
 * value is empty; an inactive mapping (neither) is left untouched.
 */
class FieldMappingTest extends Unit
{
    public function testNodeMappingIsActive(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body']);
        $this->assertTrue($mapping->isActive());
    }

    public function testUseDefaultWithoutNodeIsActive(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['default' => 'x', 'useDefault' => true]);
        $this->assertTrue($mapping->isActive());
    }

    public function testNoNodeAndNoUseDefaultIsInactive(): void
    {
        // A typed-but-unactivated default must not write anything.
        $this->assertFalse(FieldMapping::fromConfig('summary', [])->isActive());
        $this->assertFalse(FieldMapping::fromConfig('summary', ['default' => 'x'])->isActive());
        $this->assertFalse(FieldMapping::fromConfig('summary', ['options' => ['match' => 'id']])->isActive());
    }

    public function testEmptyStringNodeIsInactive(): void
    {
        // fromConfig() normalises an empty-string node to null.
        $this->assertFalse(FieldMapping::fromConfig('summary', ['node' => ''])->isActive());
    }
}
