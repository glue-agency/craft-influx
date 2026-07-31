<?php

namespace GlueAgency\Influx\Tests\unit\services;

use Codeception\Test\Unit;
use craft\base\Element;
use craft\base\ElementInterface;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\services\InspectorService;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\web\ItemRowPresenter;

/**
 * Behaviour spec for {@see InspectorService::inspectStoredLogItem()} — the log
 * detail's drill-down, which PRESENTS a stored row rather than re-inspecting it.
 * The run persisted its own presented mapping rows, so the drill-down's job is
 * to hand them back untouched (they carry the run's genuine changed flags and
 * field errors) inside the {@see InspectorService::itemRow()} envelope the Vue
 * component renders.
 *
 * What's specced here is therefore the decision tree, not any inspection: which
 * source fills which envelope key, and the three degradations — nothing stored,
 * a payload with no snapshot, and a since-deleted link. The two reaches into a
 * booted Craft (the link lookup, the fresh element load) are stubbed at their
 * seams; everything else is the real method.
 */
class StoredLogItemDrillDownTest extends Unit
{
    /** The stored snapshot standing in for a run's presented rows. */
    protected const SNAPSHOT = [
        [
            'handle'       => 'title',
            'label'        => 'Title',
            'changed'      => true,
            'error'        => null,
            'children'     => null,
            'childrenType' => null,
        ],
        [
            'handle'       => 'summary',
            'label'        => 'Summary',
            'changed'      => false,
            'error'        => 'Too long',
            'children'     => null,
            'childrenType' => null,
        ],
    ];

    public function testStoredSnapshotRendersVerbatimIntoTheRow(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make(['mappings' => ['importId' => ['node' => 'remote_id']]]);

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'     => ItemAction::UPDATED->value,
            'matchValue' => 'abc',
            'payload'    => '{"remote_id":"abc"}',
            'mappings'   => json_encode(self::SNAPSHOT),
        ]), $this->log());

        $row = $result['row'];

        // Verbatim: the run's own flags and per-field errors, not a recomputation.
        $this->assertSame(self::SNAPSHOT, $row['mappings']);
        $this->assertSame(['remote_id' => 'abc'], $row['raw']);
        $this->assertSame(ItemAction::UPDATED->value, $row['action']);
        $this->assertSame('abc', $row['matchValue']);
        $this->assertSame('importId', $row['matchAttribute']);
        $this->assertSame('remote_id', $row['matchNode']);
        $this->assertNull($row['message']);
        $this->assertNull($row['error']);
    }

    /**
     * The envelope is a contract with the log viewer, so a stored row has to fill
     * exactly the same key set a live inspection does.
     */
    public function testTheRowKeepsTheFullItemRowEnvelope(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'   => ItemAction::CREATED->value,
            'payload'  => '{"remote_id":"abc"}',
            'mappings' => json_encode(self::SNAPSHOT),
        ]), $this->log());

        $this->assertSame(array_keys(InspectorService::itemRow()), array_keys($result['row']));
    }

    public function testTheResolvedElementIsPresentedOntoTheRow(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();
        $inspector->element = $this->element(512, 'Werfkelder');

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'    => ItemAction::UPDATED->value,
            'elementId' => 512,
            'payload'   => '{"remote_id":"abc"}',
            'mappings'  => json_encode(self::SNAPSHOT),
        ]), $this->log());

        $this->assertSame(['id' => 512, 'title' => 'Werfkelder'], $result['row']['element']);
    }

    public function testADeletedElementLeavesTheRowWithoutOne(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'    => ItemAction::UPDATED->value,
            'elementId' => 512,
            'payload'   => '{"remote_id":"abc"}',
            'mappings'  => json_encode(self::SNAPSHOT),
        ]), $this->log());

        $this->assertNull($result['row']['element']);
    }

    public function testAPayloadWithoutASnapshotRendersEmptyRowsAndSaysSo(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        // A row written before the column existed, or one whose snapshot was too
        // big to store: the payload still renders, the field list can't.
        $result = $inspector->inspectStoredLogItem($this->item([
            'action'  => ItemAction::UPDATED->value,
            'payload' => '{"remote_id":"abc"}',
        ]), $this->log());

        $this->assertSame([], $result['row']['mappings']);
        $this->assertSame(['remote_id' => 'abc'], $result['row']['raw']);
        $this->assertSame('No stored field data for this item — it predates snapshot storage, or the snapshot could not be kept.', $result['row']['message']);
    }

    public function testMalformedSnapshotJsonReadsAsNoSnapshot(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'   => ItemAction::UPDATED->value,
            'payload'  => '{"remote_id":"abc"}',
            'mappings' => '{not json',
        ]), $this->log());

        $this->assertSame([], $result['row']['mappings']);
        $this->assertSame('No stored field data for this item — it predates snapshot storage, or the snapshot could not be kept.', $result['row']['message']);
    }

    public function testTheRecordsOwnMessageWinsOverTheNotice(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        // A real run-time message must never be traded for an explanation of
        // missing storage.
        $result = $inspector->inspectStoredLogItem($this->item([
            'action'  => ItemAction::ERROR->value,
            'message' => 'Element save failed: title cannot be blank.',
            'payload' => '{"remote_id":"abc"}',
        ]), $this->log());

        $this->assertSame('Element save failed: title cannot be blank.', $result['row']['message']);
    }

    /**
     * A sweep row never had a payload, and nothing to present with it — it keeps
     * answering with the four-key subset the Vue side guards for.
     */
    public function testARowWithNothingStoredKeepsTheSubsetRow(): void
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'  => ItemAction::DELETED->value,
            'message' => 'Not in feed.',
        ]), $this->log());

        $this->assertSame(['action', 'message', 'mappings', 'raw'], array_keys($result['row']));
        $this->assertSame(ItemAction::DELETED->value, $result['row']['action']);
        $this->assertSame('Not in feed.', $result['row']['message']);
        $this->assertNull($result['row']['raw']);
    }

    /**
     * The stored row is the source, so a deleted link only costs the match
     * metadata it alone can supply.
     */
    public function testAMissingLinkStillRendersTheStoredSnapshot(): void
    {
        $result = $this->inspector()->inspectStoredLogItem($this->item([
            'action'     => ItemAction::UPDATED->value,
            'matchValue' => 'abc',
            'payload'    => '{"remote_id":"abc"}',
            'mappings'   => json_encode(self::SNAPSHOT),
        ]), $this->log());

        $this->assertSame(self::SNAPSHOT, $result['row']['mappings']);
        $this->assertSame('abc', $result['row']['matchValue']);
        $this->assertNull($result['row']['matchAttribute']);
        $this->assertNull($result['row']['matchNode']);
    }

    public function testAMissingLinkWithNothingStoredIsAMessageOnlyAnswer(): void
    {
        $result = $this->inspector()->inspectStoredLogItem(
            $this->item(['action' => ItemAction::DELETED->value]),
            $this->log(),
        );

        $this->assertNull($result['row']);
        $this->assertSame("Link 'articles' no longer exists.", $result['message']);
    }

    /**
     * There is no live pipeline behind a stored row to ask, and the flag was
     * never a column — the action it produced says the same thing.
     */
    public function testIsNewIsDerivedFromTheStoredAction(): void
    {
        $this->assertTrue($this->isNewFor(ItemAction::CREATED->value));
        $this->assertTrue($this->isNewFor(ItemAction::CREATED->dryRunLabel()));
        $this->assertFalse($this->isNewFor(ItemAction::UPDATED->value));
        $this->assertFalse($this->isNewFor(ItemAction::UNCHANGED->value));
        $this->assertFalse($this->isNewFor(ItemAction::ERROR->value));
    }

    protected function isNewFor(string $action): bool
    {
        $inspector = $this->inspector();
        $inspector->link = FakeLink::make();

        $result = $inspector->inspectStoredLogItem($this->item([
            'action'   => $action,
            'payload'  => '{"remote_id":"abc"}',
            'mappings' => json_encode(self::SNAPSHOT),
        ]), $this->log());

        return $result['row']['isNew'];
    }

    /**
     * The service with its two out-of-process reaches stubbed — the link lookup
     * and the fresh element load, both of which need a booted Craft — plus an
     * element presenter that renders without one (a real chip needs a CP
     * request). Everything else is the code under test.
     */
    protected function inspector(): InspectorService
    {
        return new class() extends InspectorService {
            public ?Link $link = null;

            public ?ElementInterface $element = null;

            public function init(): void
            {
                parent::init();

                $this->rows = new class() extends ItemRowPresenter {
                    public function presentElement(ElementInterface $element): array
                    {
                        return ['id' => $element->id, 'title' => (string) $element->title];
                    }
                };
            }

            protected function linkFor(LogRecord $log): ?Link
            {
                return $this->link;
            }

            protected function storedElement(LogItemRecord $item, LogRecord $log): ?ElementInterface
            {
                return $this->element;
            }
        };
    }

    protected function element(int $id, string $title): ElementInterface
    {
        $element = $this->createMock(Element::class);
        $element->id = $id;
        $element->title = $title;

        return $element;
    }

    /**
     * A log-item record standing in for a stored one: attribute reads/writes go
     * to a plain array instead of the table schema, which keeps the spec free of
     * a database (mirroring LogBufferLifecycleTest's log record).
     *
     * @param array<string, mixed> $values
     */
    protected function item(array $values): LogItemRecord
    {
        $item = new class() extends LogItemRecord {
            /** @var array<string, mixed> */
            public array $attrs = [];

            public function __get($name)
            {
                return $this->attrs[$name] ?? null;
            }

            public function __set($name, $value)
            {
                $this->attrs[$name] = $value;
            }
        };
        $item->attrs = $values + ['id' => 1, 'logId' => 1];

        return $item;
    }

    protected function log(?string $siteHandle = null): LogRecord
    {
        $log = new class() extends LogRecord {
            /** @var array<string, mixed> */
            public array $attrs = [];

            public function __get($name)
            {
                return $this->attrs[$name] ?? null;
            }

            public function __set($name, $value)
            {
                $this->attrs[$name] = $value;
            }
        };
        $log->attrs = ['id' => 1, 'linkHandle' => 'articles', 'siteHandle' => $siteHandle];

        return $log;
    }
}
