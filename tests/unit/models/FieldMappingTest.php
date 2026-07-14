<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\RemoteItem;

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

    public function testAddressedByWhenNodePresentWithValue(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body']);
        $this->assertTrue($mapping->addressedBy(new RemoteItem(['body' => 'hello'])));
    }

    public function testAddressedByWhenNodePresentButEmptyString(): void
    {
        // An explicit empty string IS addressed — the feed is saying "empty",
        // which clears the field. Only an absent node is left untouched.
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body']);
        $this->assertTrue($mapping->addressedBy(new RemoteItem(['body' => ''])));
    }

    public function testNotAddressedWhenNodeAbsentFromItem(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body']);
        $this->assertFalse($mapping->addressedBy(new RemoteItem(['other' => 'x'])));
    }

    public function testAddressedByDefaultWhenNodeAbsent(): void
    {
        // A node-less item still applies an explicit default.
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body', 'default' => 'x', 'useDefault' => true]);
        $this->assertTrue($mapping->addressedBy(new RemoteItem(['other' => 'x'])));
    }

    public function testNotAddressedWhenInactive(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['default' => 'x']);
        $this->assertFalse($mapping->addressedBy(new RemoteItem([])));
    }

    public function testUsesDefaultWithExplicitUseDefault(): void
    {
        // No node, explicit "— use default —" with a non-empty default.
        $mapping = FieldMapping::fromConfig('summary', ['default' => 'x', 'useDefault' => true]);
        $this->assertTrue($mapping->usesDefault(new RemoteItem([])));
    }

    public function testUsesDefaultWhenMappedNodeIsEmpty(): void
    {
        // Node mapped, present but empty ('' is addressed) — resolve() falls
        // back to the default, so the applied value is the default.
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body', 'default' => 'x']);
        $this->assertTrue($mapping->usesDefault(new RemoteItem(['body' => ''])));
    }

    public function testDoesNotUseDefaultWhenFeedProvidesValue(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body', 'default' => 'x']);
        $this->assertFalse($mapping->usesDefault(new RemoteItem(['body' => 'hello'])));
    }

    public function testDoesNotUseDefaultWhenNodeAbsent(): void
    {
        // An absent node is unaddressed ("left untouched") — the applier gates
        // it out before the default can apply, so it's the missing-node state,
        // not use-default. True mutual exclusivity with that pill.
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body', 'default' => 'x']);
        $this->assertFalse($mapping->usesDefault(new RemoteItem(['other' => 'y'])));
        $this->assertFalse($mapping->addressedBy(new RemoteItem(['other' => 'y'])));
    }

    public function testDoesNotUseDefaultWithoutADefault(): void
    {
        $mapping = FieldMapping::fromConfig('summary', ['node' => 'body']);
        $this->assertFalse($mapping->usesDefault(new RemoteItem(['body' => ''])));
    }

    public function testEmptyStringDefaultDoesNotCountAsUsed(): void
    {
        // An empty-string default resolves to null (leave untouched), so it is
        // never reported as "used".
        $mapping = FieldMapping::fromConfig('summary', ['default' => '', 'useDefault' => true]);
        $this->assertFalse($mapping->usesDefault(new RemoteItem([])));
    }
}
