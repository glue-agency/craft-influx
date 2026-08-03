<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\elements\Entry;
use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\LinksService;

/**
 * The config <-> column mapping, driven entirely off {@see Link::CONFIG_FIELDS},
 * {@see Link::JSON_FIELDS} and {@see Link::COLUMN_CASTS}. These assertions are
 * the covering ones: they fail if a config field is added without a column, a
 * cast, or its JSON classification — which is the whole point of deriving both
 * directions from the declarations.
 *
 * Pure: no DB. The read side is exercised against a faked row (exactly what the
 * write side produces, plus the identity columns the change handler adds).
 */
class LinkColumnMappingTest extends Unit
{
    public function testColumnSetDerivesExactlyFromConfigFields(): void
    {
        $this->assertSame(
            Link::CONFIG_FIELDS,
            array_keys(LinksService::columnValuesFromConfig([])),
            'The written columns must be exactly the config fields — no hand-listed extras, none missing.',
        );
    }

    public function testEveryNonJsonConfigFieldDeclaresACast(): void
    {
        $scalars = array_values(array_diff(Link::CONFIG_FIELDS, Link::JSON_FIELDS));

        foreach ($scalars as $field) {
            $this->assertArrayHasKey(
                $field,
                Link::COLUMN_CASTS,
                "Config field '{$field}' is stored as a scalar column but declares no cast.",
            );
        }
    }

    public function testJsonFieldsDeclareNoCastAndAreASubsetOfConfigFields(): void
    {
        foreach (Link::JSON_FIELDS as $field) {
            $this->assertContains($field, Link::CONFIG_FIELDS, "JSON field '{$field}' isn't a config field.");
            $this->assertArrayNotHasKey(
                $field,
                Link::COLUMN_CASTS,
                "JSON field '{$field}' must be JSON-encoded, not cast.",
            );
        }
    }

    public function testCastsBeyondTheConfigFieldsAreRuntimeColumnsOnly(): void
    {
        // The map covers the runtime columns too, so hydration reads them off the
        // same declaration; nothing else may sneak in.
        $this->assertSame(
            ['id', 'lastRunAt', 'lastLogId', 'dateCreated', 'dateUpdated'],
            array_values(array_diff(array_keys(Link::COLUMN_CASTS), Link::CONFIG_FIELDS)),
        );
    }

    public function testJsonColumnsAreEncodedAndScalarsCoerced(): void
    {
        $columns = LinksService::columnValuesFromConfig([
            'handle'      => 'articles',
            'name'        => 'Articles',
            'elementType' => Entry::class,
            'endpoint'    => 'https://example.test/articles',
            'mappings'    => ['importId' => ['node' => 'id']],
            'processing'  => ProcessingAction::defaults(),
            'backup'      => 1,
            'sortOrder'   => '3',
        ]);

        $this->assertSame('{"importId":{"node":"id"}}', $columns['mappings']);
        $this->assertSame('["create","update"]', $columns['processing']);
        $this->assertTrue($columns['backup']);
        $this->assertSame(3, $columns['sortOrder']);
        $this->assertSame('articles', $columns['handle']);
    }

    public function testOmittedFieldsWriteNullForJsonColumnsAndZeroValuesOtherwise(): void
    {
        $columns = LinksService::columnValuesFromConfig([]);

        foreach (Link::JSON_FIELDS as $field) {
            $this->assertNull($columns[$field], "Empty '{$field}' must be stored as NULL, not as '[]'.");
        }

        $this->assertSame('', $columns['name']);
        $this->assertNull($columns['endpoint']);
        $this->assertFalse($columns['backup']);
        $this->assertNull($columns['sortOrder']);
    }

    public function testConfigRoundTripsThroughColumnsBackOntoALink(): void
    {
        $config = [
            'handle'          => 'articles',
            'name'            => 'Articles',
            'elementType'     => Entry::class,
            'elementCriteria' => ['section' => 'news', 'type' => 'article'],
            'endpoint'        => 'https://example.test/articles',
            'itemEndpoint'    => 'https://example.test/articles/{id}',
            'siteEndpoints'   => [['site' => 'nl', 'endpoint' => 'https://example.test/nl']],
            'auth'            => ['type' => 'bearer', 'token' => '$TOKEN'],
            'rootNode'        => 'data.items',
            'paginatorNode'   => 'meta.next',
            'totalCountNode'  => 'meta.total',
            'pageCountNode'   => 'meta.pages',
            'match'           => ['attribute' => 'importId'],
            'mappings'        => ['importId' => ['node' => 'id']],
            'processing'      => [ProcessingAction::CREATE->value],
            'offset'          => ['hour' => ['since' => '-1 hour', 'queryParam' => 'modified_since']],
            'backup'          => true,
            'sortOrder'       => 3,
        ];

        $link = $this->hydrate($this->row($config));

        $this->assertSame($config, $link->getConfig(), 'config -> columns -> row -> Link must be lossless.');
        $this->assertSame(7, $link->id);
        $this->assertSame('a-uid', $link->uid);
    }

    public function testStringyRowValuesAreCoercedOnHydration(): void
    {
        // Some drivers hand booleans / ints back as strings — the cast map is
        // what normalizes them. (The datetime casts are left out: they route
        // through DateTimeHelper, which reads the app's timezone and so needs a
        // booted Craft; their empty branch is covered below.)
        $link = $this->hydrate([
            'id'        => '7',
            'uid'       => 'a-uid',
            'handle'    => 'articles',
            'name'      => 'Articles',
            'backup'    => '0',
            'sortOrder' => '2',
            'lastLogId' => '19',
        ]);

        $this->assertSame(7, $link->id);
        $this->assertFalse($link->backup);
        $this->assertSame(2, $link->sortOrder);
        $this->assertSame(19, $link->lastLogId);
    }

    public function testEmptyDateColumnsHydrateAsNull(): void
    {
        foreach (['lastRunAt', 'dateCreated', 'dateUpdated'] as $column) {
            $this->assertNull(Link::castColumnValue($column, null));
            $this->assertNull(Link::castColumnValue($column, ''));
        }
    }

    public function testUndeclaredColumnsPassThroughUncast(): void
    {
        $this->assertSame('a-uid', Link::castColumnValue('uid', 'a-uid'));
    }

    public function testMissingRuntimeStateHydratesAsNull(): void
    {
        $link = $this->hydrate($this->row(['handle' => 'articles', 'name' => 'Articles']));

        $this->assertNull($link->lastRunAt);
        $this->assertNull($link->lastLogId);
        $this->assertNull($link->dateCreated);
        $this->assertNull($link->dateUpdated);
    }

    public function testUnreadableJsonColumnsHydrateAsEmptyArrays(): void
    {
        $link = $this->hydrate(['mappings' => 'not json', 'match' => null, 'processing' => '']);

        $this->assertSame([], $link->mappings);
        $this->assertSame([], $link->match);
        $this->assertSame([], $link->processing);
    }

    /**
     * A DB row the way the PC change handler writes it: the config columns plus
     * the identity columns. The `dateCreated` / `dateUpdated` columns a real row
     * also carries are left out — they hydrate through the same DateTimeHelper
     * the `lastRunAt` cast does, which needs a booted Craft (see below); their
     * empty branch is covered on its own.
     */
    protected function row(array $config): array
    {
        return LinksService::columnValuesFromConfig($config) + [
            'id'  => 7,
            'uid' => 'a-uid',
        ];
    }

    /**
     * {@see LinksService::linkFromRow()} is the hydration seam; exposing it keeps
     * the round trip testable without a DB.
     */
    protected function hydrate(array $row): Link
    {
        $service = new class() extends LinksService {
            public function hydrate(array $row): Link
            {
                return $this->linkFromRow($row);
            }
        };

        return $service->hydrate($row);
    }
}
