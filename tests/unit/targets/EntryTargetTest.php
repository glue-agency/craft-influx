<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use GlueAgency\Influx\targets\EntryTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * EntryTarget's element predicates: `targetsElement()` is the STRUCTURAL claim
 * (right type + section/type scope); `claimsElement()` is that PLUS a non-empty
 * match value. The split is what lets the "Sync from remote" button surface for
 * an in-scope entry that has no match value yet (rendered disabled).
 *
 * Entries here are anonymous Entry subclasses with a skipped constructor and
 * overridden section/type getters, so the spec runs without a booted Craft.
 */
class EntryTargetTest extends Unit
{
    public function testTargetsElementIgnoresMatchValueWhereClaimsRequiresIt(): void
    {
        $target = new EntryTarget();
        $link = FakeLink::make([
            'elementType'     => Entry::class,
            'elementCriteria' => ['section' => 'news', 'type' => 'article'],
            'match'           => ['attribute' => 'importId'],
        ]);

        // In scope, but no match value: structurally targeted, not yet claimed.
        $entry = $this->entry('news', 'article', null);
        $this->assertTrue($target->targetsElement($link, $entry));
        $this->assertFalse($target->claimsElement($link, $entry));

        // In scope, with a match value: both true.
        $entry = $this->entry('news', 'article', 'abc');
        $this->assertTrue($target->targetsElement($link, $entry));
        $this->assertTrue($target->claimsElement($link, $entry));

        // An empty-string match value is not a value.
        $entry = $this->entry('news', 'article', '');
        $this->assertTrue($target->targetsElement($link, $entry));
        $this->assertFalse($target->claimsElement($link, $entry));
    }

    public function testStructuralScopeGatesBothPredicates(): void
    {
        $target = new EntryTarget();
        $link = FakeLink::make([
            'elementType'     => Entry::class,
            'elementCriteria' => ['section' => 'news', 'type' => 'article'],
            'match'           => ['attribute' => 'importId'],
        ]);

        // Wrong section — out of scope even with a match value.
        $entry = $this->entry('blog', 'article', 'abc');
        $this->assertFalse($target->targetsElement($link, $entry));
        $this->assertFalse($target->claimsElement($link, $entry));

        // Wrong entry type — likewise.
        $entry = $this->entry('news', 'story', 'abc');
        $this->assertFalse($target->targetsElement($link, $entry));
        $this->assertFalse($target->claimsElement($link, $entry));
    }

    public function testUnscopedLinkTargetsAnyEntry(): void
    {
        $target = new EntryTarget();
        $link = FakeLink::make([
            'elementType' => Entry::class,
            'match'       => ['attribute' => 'importId'],
        ]);

        // No section/type criteria: every entry is in scope.
        $entry = $this->entry('anything', 'whatever', 'abc');
        $this->assertTrue($target->targetsElement($link, $entry));
        $this->assertTrue($target->claimsElement($link, $entry));
    }

    public function testNonEntryIsNeverTargeted(): void
    {
        $target = new EntryTarget();
        $link = FakeLink::make(['elementType' => Entry::class, 'match' => ['attribute' => 'importId']]);

        $notAnEntry = new class() extends Element {
            public function __construct()
            {
                // Skip Element::init()'s Craft dependencies.
            }
        };
        $this->assertFalse($target->targetsElement($link, $notAnEntry));
        $this->assertFalse($target->claimsElement($link, $notAnEntry));
    }

    protected function entry(?string $section, ?string $type, mixed $match): Entry
    {
        $entry = new class() extends Entry {
            public mixed $importId = null;
            public ?Section $sectionStub = null;
            public ?EntryType $typeStub = null;

            public function __construct()
            {
                // Skip Entry::init()'s Craft dependencies.
            }

            public function getSection(): ?Section
            {
                return $this->sectionStub;
            }

            public function getType(): EntryType
            {
                return $this->typeStub;
            }
        };

        $entry->sectionStub = $section === null ? null : $this->section($section);
        $entry->typeStub = $type === null ? null : $this->entryType($type);
        // The link's match attribute is `importId`; expose it as a real
        // property so the target reads it directly rather than via the field
        // magic getter (which would need a booted Craft).
        $entry->importId = $match;

        return $entry;
    }

    protected function section(string $handle): Section
    {
        return new class($handle) extends Section {
            public function __construct(string $handle)
            {
                $this->handle = $handle;
            }
        };
    }

    protected function entryType(string $handle): EntryType
    {
        return new class($handle) extends EntryType {
            public function __construct(string $handle)
            {
                $this->handle = $handle;
            }
        };
    }
}
