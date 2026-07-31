<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CraftCategoriesField;
use craft\fields\Entries as CraftEntriesField;
use craft\fields\Tags as CraftTagsField;
use GlueAgency\Influx\exceptions\MappingValueException;
use GlueAgency\Influx\fields\Categories;
use GlueAgency\Influx\fields\Entries;
use GlueAgency\Influx\fields\Relation;
use GlueAgency\Influx\fields\Tags;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\ElementLookupCache;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\Tests\unit\Support\GroupScopedRelationSpy;
use GlueAgency\Influx\Tests\unit\Support\RelationCreateSpy;

/**
 * Behaviour spec for a REFUSED element creation
 * ({@see Relation::persistNewElement()}).
 *
 * The bug this pins, straight out of QA: a `tags` mapping on `topics.name`
 * matching by id (the runtime default) misses every lookup, so `create` has to
 * produce the tag — and Craft refuses the save because the title is already
 * taken. Returning null for that made the reference contribute nothing; with
 * every reference in the same boat, {@see Relation::parse()} returned null and
 * {@see \GlueAgency\Influx\fields\RelationalField::apply()} wrote [] — silently
 * DETACHING the entry's existing tags while the row reported success. A partial
 * failure thinned the same relation one element at a time.
 *
 * The contract now: a refused save throws, the mapping row carries the error,
 * and the field is left exactly as it was. Only genuine "cannot create here"
 * preconditions — no resolvable group/section, creation turned off, a flavour
 * that doesn't create at all — still resolve to nothing, which is the
 * documented clear.
 *
 * Bootless throughout: the lookup, the element class and the save are stubbed
 * at the flavours' own seams ({@see RelationCreateSpy}), so these are the REAL
 * Tags / Categories / Entries strategies under test.
 */
class RelationCreateFailureTest extends Unit
{
    public function testARefusedCreateErrorsTheMappingInsteadOfDetachingTheRelation(): void
    {
        $tags = $this->tags('Title "Koken" has already been taken.');
        $context = $this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken'], ['create' => '1']);

        try {
            $parsed = $tags->parse($context);
            $this->fail('A refused create must not resolve to ' . var_export($parsed, true) . ' — that clears the field.');
        } catch (MappingValueException $e) {
            $this->assertStringContainsString("'Koken'", $e->getMessage(), 'The message names the feed value that could not be created.');
            $this->assertStringContainsString('title: Title "Koken" has already been taken.', $e->getMessage(), "...and carries Craft's own verdict.");
        }

        $this->assertCount(1, $tags->saved, 'The save was genuinely attempted.');
    }

    public function testEveryReferenceFailingToCreateThrowsRatherThanReturningNothing(): void
    {
        $tags = $this->tags('Title has already been taken.');
        $context = $this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken', 'Bakken'], ['create' => '1']);

        try {
            $parsed = $tags->parse($context);
            $this->fail('Zero resolutions must throw, not hand back ' . var_export($parsed, true) . ' for apply() to clear the relation with.');
        } catch (MappingValueException $e) {
            $this->assertStringContainsString("'Koken'", $e->getMessage());
        }

        $this->assertCount(1, $tags->saved, 'The first refusal aborts the parse; the rest of the list is moot.');
    }

    public function testAPartialFailureNeverThinsTheRelationSilently(): void
    {
        $tags = $this->tags('Title "Bakken" has already been taken.');
        $tags->existing['Koken'] = $this->relatedElement(5);
        $context = $this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken', 'Bakken'], ['create' => '1']);

        try {
            $parsed = $tags->parse($context);
            $this->fail('A relation the feed spelled out in full must not be written one element short: ' . var_export($parsed, true));
        } catch (MappingValueException $e) {
            $this->assertStringContainsString("'Bakken'", $e->getMessage(), 'The failing reference is the one named, not the one that resolved.');
        }

        $this->assertSame(['Koken', 'Bakken'], $tags->lookedUp);
        $this->assertCount(1, $tags->saved, 'Only the reference that missed is created.');
    }

    public function testTheEntriesFlavourRefusesJustAsLoudly(): void
    {
        $entries = $this->entries('Title cannot be blank.');
        $context = $this->context(CraftEntriesField::class, 'section:abc', ['Koken'], [
            'create' => '1',
            'group'  => ['section' => 'topics'],
        ]);

        try {
            $entries->parse($context);
            $this->fail('The Entries flavour must error a refused create too.');
        } catch (MappingValueException $e) {
            $this->assertStringContainsString("'Koken'", $e->getMessage());
            $this->assertStringContainsString('title: Title cannot be blank.', $e->getMessage());
        }
    }

    public function testAnAcceptedCreateResolvesAndIsCachedForLaterItems(): void
    {
        $tags = $this->tags();
        $cache = new ElementLookupCache();

        $this->assertSame([100], $tags->parse($this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken'], ['create' => '1'], lookups: $cache)));
        $this->assertSame([100], $tags->parse($this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken'], ['create' => '1'], lookups: $cache)));

        $this->assertSame(['Koken'], $tags->lookedUp, 'The created element flipped the cached miss to a hit.');
        $this->assertCount(1, $tags->saved, 'A second item carrying the same value reuses it instead of re-creating it.');
    }

    public function testCreationTurnedOffKeepsTheDocumentedClear(): void
    {
        $categories = $this->categories('Title has already been taken.');
        $context = $this->context(CraftCategoriesField::class, 'group:abc', ['Koken']);

        $this->assertNull($categories->parse($context), 'Creation is opt-in for categories, so an unresolved reference still clears.');
        $this->assertSame([], $categories->saved);
    }

    public function testAFlavourThatDoesNotCreateStillResolvesToNothing(): void
    {
        $base = $this->baseFlavour();
        $context = $this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken'], ['create' => '1']);

        $this->assertNull($base->parse($context), "The base createMissing()'s null keeps meaning 'this flavour doesn't create'.");
        $this->assertSame([], $base->saved);
    }

    public function testDryRunNeverReachesTheCreateAtAll(): void
    {
        $tags = $this->tags('Title has already been taken.');
        $context = $this->context(CraftTagsField::class, 'taggroup:xyz', ['Koken'], ['create' => '1'], dryRun: true);

        $this->assertNull($tags->parse($context), 'The inspector resolves what it can and writes nothing.');
        $this->assertSame([], $tags->saved);
    }

    /** The real Tags flavour, its Craft-dependent halves stubbed. */
    protected function tags(?string $saveError = null): Tags
    {
        $tags = new class() extends Tags {
            use GroupScopedRelationSpy;
            use RelationCreateSpy;
        };
        $tags->saveError = $saveError;

        return $tags;
    }

    /** The real Categories flavour (creation opt-in), stubbed the same way. */
    protected function categories(?string $saveError = null): Categories
    {
        $categories = new class() extends Categories {
            use GroupScopedRelationSpy;
            use RelationCreateSpy;
        };
        $categories->saveError = $saveError;

        return $categories;
    }

    /**
     * The real Entries flavour, with the section/type resolution stubbed on top
     * of the shared seams — {@see Entries::createTarget()} reads handles through
     * Craft's sections service.
     */
    protected function entries(?string $saveError = null): Entries
    {
        $entries = new class() extends Entries {
            use RelationCreateSpy;

            protected function createTarget(FieldContext $context): array
            {
                return [1, 2];
            }
        };
        $entries->saveError = $saveError;

        return $entries;
    }

    /**
     * The base flavour: `create` on, but no createMissing() implementation — the
     * documented silent no-op that stays a no-op.
     */
    protected function baseFlavour(): Relation
    {
        return new class() extends Relation {
            use RelationCreateSpy;
        };
    }

    /** An element that already exists, so a reference resolves without creating. */
    protected function relatedElement(int $id): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->id = $id;

        return $element;
    }

    protected function field(string $class, string $source): BaseRelationField
    {
        $field = $this->createMock($class);
        $field->source = $source;

        return $field;
    }

    /**
     * The QA repro's mapping: `topics.name` against a feed shipping topics as a
     * list of objects, matched by id (the runtime default) — so every lookup
     * misses on a name and creation is the only thing that can resolve them.
     *
     * @param list<string> $names
     * @param array<string, mixed> $options
     */
    protected function context(
        string $fieldClass,
        string $source,
        array $names,
        array $options = [],
        bool $dryRun = false,
        ?ElementLookupCache $lookups = null,
    ): FieldContext {
        return new FieldContext(
            craftField: $this->field($fieldClass, $source),
            handle: 'topics',
            mapping: FieldMapping::fromConfig('topics', ['node' => 'topics.name', 'options' => $options]),
            item: new RemoteItem(['topics' => array_map(static fn(string $name): array => ['name' => $name], $names)]),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
            dryRun: $dryRun,
            lookups: $lookups,
        );
    }
}
