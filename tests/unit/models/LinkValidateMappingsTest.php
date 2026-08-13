<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\fields\Matrix as CraftMatrixField;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappableField;
use GlueAgency\Influx\services\FieldsService;
use GlueAgency\Influx\targets\AbstractElementTarget;
use GlueAgency\Influx\targets\ElementTargetInterface;
use LogicException;

/**
 * {@see Link::validateMappings()}: the dispatch half of a strategy's own rules.
 *
 * What the link owns here is only WHICH strategy answers for a handle — a fact
 * about the target's field layout, which nothing but a link knows — and where
 * the answer lands. What "invalid" means is the strategy's, and is specced
 * against each strategy ({@see \GlueAgency\Influx\Tests\unit\fields\MatrixFieldTest}).
 *
 * Both services are injected through the `target()` / `fieldsService()` seams,
 * since the plugin singleton isn't bootstrapped in a unit test.
 */
class LinkValidateMappingsTest extends Unit
{
    public function testAStrategysMessagesLandUnderTheRowsOwnKey(): void
    {
        $link = $this->link(['content_blocks' => []]);
        $link->validate();

        // Dotted, because that is how Yii addresses one row of a collection —
        // and it's what lets the builder render the message ON that row.
        $this->assertSame(
            ['Blocks are built from a list, so this mapping needs a source node.'],
            $link->getErrors('mappings.content_blocks'),
        );
    }

    public function testAValidMappingAddsNothing(): void
    {
        $link = $this->link(['content_blocks' => ['node' => 'items']]);
        $link->validate();

        $this->assertSame([], $link->getErrors('mappings.content_blocks'));
    }

    public function testAHandleWithNoMappingIsNeverAsked(): void
    {
        $link = $this->link([]);
        $link->validate();

        $this->assertSame([], $link->getErrors('mappings.content_blocks'));
    }

    public function testANativeAttributeIsTheTargetsToJudge(): void
    {
        // A native carries no `fieldClass`, which is what a strategy is filed
        // under — so the dispatch skips it rather than resolving the fallback
        // strategy and asking it about an attribute it knows nothing about.
        $link = $this->link(['title' => ['node' => 'name']], native: true);
        $link->validate();

        $this->assertSame([], $link->getErrors('mappings.title'));
    }

    public function testAnUnresolvableTargetValidatesNothingRatherThanFailing(): void
    {
        // An uninstalled plugin's element type, or a link mid-configuration.
        $link = $this->link(['content_blocks' => []], withTarget: false);
        $link->validate();

        $this->assertSame([], $link->getErrors('mappings.content_blocks'));
    }

    /**
     * A link whose target offers one Matrix field, `content_blocks`. Every
     * mapping gets one mapped block type folded in, since the strategy's rules
     * only apply to a row carrying active block rows at all.
     *
     * @param array<string, array<string, mixed>> $mappings
     */
    protected function link(array $mappings, bool $native = false, bool $withTarget = true): Link
    {
        foreach ($mappings as $handle => $config) {
            $mappings[$handle] = $config + [
                'options' => ['sourceKey_quote' => 'quote'],
                'blocks'  => ['quote' => ['fields' => ['cite' => ['node' => 'cite']]]],
            ];
        }

        $link = new class() extends Link {
            public ?ElementTargetInterface $targetStub = null;

            public ?FieldsService $fieldsStub = null;

            protected function target(): ?ElementTargetInterface
            {
                return $this->targetStub;
            }

            protected function fieldsService(): ?FieldsService
            {
                return $this->fieldsStub;
            }
        };

        $link->handle = 'articles';
        $link->name = 'Articles';
        $link->elementType = Entry::class;
        $link->endpoint = 'https://example.test/feed';
        $link->mappings = $mappings;
        $link->fieldsStub = new FieldsService();

        if ($withTarget) {
            $link->targetStub = $this->target($native);
        }

        return $link;
    }

    protected function target(bool $native): ElementTargetInterface
    {
        $target = new class() extends AbstractElementTarget {
            public static bool $native = false;

            public static function elementType(): string
            {
                return Entry::class;
            }

            public function getMappableFields(Link $link): array
            {
                if (static::$native) {
                    return [MappableField::native('title', 'Title', 'Content')];
                }

                return [MappableField::custom('content_blocks', 'Content', 'Content', CraftMatrixField::class)];
            }

            public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?ElementInterface
            {
                return null;
            }

            public function buildNew(Link $link, ?int $siteId = null): ElementInterface
            {
                throw new LogicException('Not exercised here.');
            }
        };

        $target::$native = $native;

        return $target;
    }
}
