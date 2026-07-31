<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry as CraftEntryElement;
use GlueAgency\Influx\fields\Relation;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Behaviour spec for how a relation applies its configured DEFAULT, as opposed
 * to a feed value ({@see Relation::parse()}).
 *
 * The default is an element picked in the CP ({@see Relation::defaultEditor()}),
 * so it is an id — matched by id whatever `options.match` says, and never
 * created when it doesn't resolve. Same rule as the native author's
 * ({@see \GlueAgency\Influx\targets\EntryTarget::resolveAuthorId()}).
 */
class RelationDefaultMatchTest extends Unit
{
    public function testFeedValueUsesTheConfiguredMatch(): void
    {
        $strategy = $this->strategy();
        $context = $this->context(
            feed: ['ref' => 'Some article'],
            mapping: [
                'node'    => 'ref',
                'default' => '77',
                'options' => ['match' => 'title'],
            ],
        );

        $strategy->parse($context);

        $this->assertSame([['title', 'Some article']], $strategy->calls);
    }

    public function testDefaultFallbackMatchesByIdEvenWithANodeMapped(): void
    {
        // The node is mapped but empty for this item, so resolve() falls back to
        // the picked default — an id, not a title. (An ABSENT node isn't
        // addressed at all: the applier leaves the field untouched and parse()
        // never runs — see FieldMapping::addressedBy().)
        $strategy = $this->strategy();
        $context = $this->context(
            feed: ['ref' => ''],
            mapping: [
                'node'    => 'ref',
                'default' => '77',
                'options' => ['match' => 'title'],
            ],
        );

        $strategy->parse($context);

        $this->assertSame([['id', '77']], $strategy->calls);
    }

    public function testUseDefaultOnlyMatchesById(): void
    {
        $strategy = $this->strategy();
        $context = $this->context(
            feed: [],
            mapping: [
                'useDefault' => true,
                'default'    => '77',
                'options'    => ['match' => 'slug'],
            ],
        );

        $strategy->parse($context);

        $this->assertSame([['id', '77']], $strategy->calls);
    }

    public function testADefaultThatMissesNeverCreates(): void
    {
        // A default that no longer resolves is a DELETED picked element, not a
        // reference the feed asked us to materialise: creating here would conjure
        // an element titled '77'.
        $strategy = $this->strategy(finds: false);
        $context = $this->context(
            feed: [],
            mapping: [
                'useDefault' => true,
                'default'    => '77',
                'options'    => ['create' => true],
            ],
        );

        $this->assertNull($strategy->parse($context));
        $this->assertSame(0, $strategy->createCalls);
    }

    public function testAMultiElementDefaultLooksUpEveryPickedId(): void
    {
        // A default picked for a multi-relation field is a LIST of ids — the CP
        // picker allows as many as the field's maxRelations
        // ({@see \GlueAgency\Influx\services\LinkBuilderService::elementSelectConfigFor()}).
        // Each id resolves on its own, still by id, and still without creating.
        $strategy = $this->strategy();
        $strategy->stubsByValue = ['77' => $this->relatedElement(77), '88' => $this->relatedElement(88)];
        $context = $this->context(
            feed: [],
            mapping: [
                'useDefault' => true,
                'default'    => ['77', '88'],
                'options'    => ['match' => 'slug', 'create' => true],
            ],
        );

        $this->assertSame([77, 88], $strategy->parse($context));
        $this->assertSame([['id', '77'], ['id', '88']], $strategy->calls);
        $this->assertSame(0, $strategy->createCalls);
    }

    public function testAMultiElementDefaultKeepsThePicksThatStillResolve(): void
    {
        // One picked element has since been deleted. The relation still gets the
        // others — dropping the whole default over one stale id would detach
        // relations the entry legitimately had.
        $strategy = $this->strategy(finds: false);
        $strategy->stubsByValue = ['88' => $this->relatedElement(88)];
        $context = $this->context(
            feed: [],
            mapping: [
                'useDefault' => true,
                'default'    => ['77', '88'],
            ],
        );

        $this->assertSame([88], $strategy->parse($context));
    }

    public function testAFeedValueThatMissesStillCreates(): void
    {
        $strategy = $this->strategy(finds: false);
        $context = $this->context(
            feed: ['ref' => 'Some article'],
            mapping: [
                'node'    => 'ref',
                'options' => ['match' => 'title', 'create' => true],
            ],
        );

        $strategy->parse($context);

        $this->assertSame(1, $strategy->createCalls);
    }

    /**
     * Anonymous Relation recording every (match, value) pair findOne() is asked
     * for and every createMissing() it reaches — the same no-boot seam
     * {@see RelationCacheTest} stubs. `$finds` decides whether the stubbed
     * lookup resolves at all; `$stubsByValue` overrides that per reference, so a
     * multi-value default can resolve to distinct elements (or miss on one).
     */
    protected function strategy(bool $finds = true): Relation
    {
        $strategy = new class() extends Relation {
            /** @var list<array{0: string, 1: mixed}> */
            public array $calls = [];

            public int $createCalls = 0;

            public ?ElementInterface $stub = null;

            /** @var array<string, ElementInterface> */
            public array $stubsByValue = [];

            protected function elementType(): string
            {
                return CraftEntryElement::class;
            }

            protected function findOne(FieldContext $context, string $match, mixed $value): ?ElementInterface
            {
                $this->calls[] = [$match, $value];

                return $this->stubsByValue[(string) $value] ?? $this->stub;
            }

            protected function createMissing(FieldContext $context, mixed $value): ?ElementInterface
            {
                $this->createCalls++;

                return null;
            }
        };
        $strategy->stub = $finds ? $this->relatedElement() : null;

        return $strategy;
    }

    protected function relatedElement(int $id = 5): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->id = $id;

        return $element;
    }

    protected function context(array $feed, array $mapping): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'related',
            mapping: FieldMapping::fromConfig('related', $mapping),
            item: new RemoteItem($feed),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
