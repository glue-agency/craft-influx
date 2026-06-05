<?php

namespace TDM\Influx\records;

use craft\db\ActiveRecord;
use TDM\Influx\db\Table;

/**
 * Database row for an Influx link.
 *
 * The `influx_links` table is the runtime source of truth — all reads in
 * {@see \TDM\Influx\services\LinksService} go through a plain query against
 * this table. Project Config exists only as a deployment channel: saves
 * write to PC, and the PC change listeners upsert this row.
 *
 * This ActiveRecord is intentionally a thin shell. Most callers use the
 * `Link` model (populated from a query row) rather than the record itself;
 * the record class is here mainly for uid ↔ id lookups via Craft's `Db`
 * helpers.
 *
 * @property int     $id
 * @property string  $name
 * @property string  $handle
 * @property string  $elementType
 * @property ?string $elementCriteria  JSON
 * @property ?string $endpoint
 * @property ?string $itemEndpoint
 * @property ?string $siteEndpoints    JSON
 * @property ?string $auth             JSON
 * @property ?string $rootNode
 * @property ?string $paginatorNode
 * @property ?string $match            JSON
 * @property ?string $mappings         JSON (potentially large)
 * @property ?string $processing       JSON
 * @property ?string $offset           JSON
 * @property bool    $backup
 * @property string  $dateCreated
 * @property string  $dateUpdated
 * @property string  $uid
 */
class Link extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::LINKS;
    }
}
