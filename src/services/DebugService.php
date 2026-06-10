<?php

namespace TDM\Influx\services;

use Cake\Utility\Hash;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use TDM\Influx\Influx;
use TDM\Influx\models\OffsetPreset;
use TDM\Influx\models\Link;
use TDM\Influx\targets\ElementTargetInterface;

/**
 * Strict dry-run inspector for a link. Mirrors what SynchronizationService
 * would do for the first page of items, but writes nothing — no logs, no
 * element saves, no cooldown marks. Used by the CP "Debug" view on the
 * Links overview.
 */
class DebugService extends Component
{
    public const DEFAULT_LIMIT = 25;

    /**
     * Per-site dry-run, yielding events suitable for Server-Sent Events:
     *
     *   ['type' => 'meta',  'data' => [...]]   — site metadata, once at start
     *   ['type' => 'item',  'data' => [...]]   — one per processed item
     *   ['type' => 'error', 'data' => [...]]   — non-recoverable fetch/setup error
     *
     * The generator finishes naturally when the first page is exhausted or the
     * limit is reached; callers should send their own "done" sentinel.
     */
    public function streamSite(Link $link, ?string $siteHandle, int $limit, ?string $offset = null): \Generator
    {
        $plugin = Influx::getInstance();
        $target = $plugin->targets->forLink($link);

        $matchAttr = $link->matchAttribute();
        $matchNode = $matchAttr ? ($link->mappings[$matchAttr]['node'] ?? null) : null;

        [$queryParams, $offsetLabel] = OffsetPreset::forLink($link, $offset)?->resolve() ?? [[], null];

        if (!$target) {
            yield [
                'type' => 'error',
                'data' => [
                    'message' => "No element target registered for '{$link->elementType}'.",
                ],
            ];
            return;
        }

        $url = $plugin->data->endpoints()->listUrlForDisplay($link, $siteHandle);

        try {
            $data = $plugin->data->fetch($link, $siteHandle, $queryParams);
        } catch (\Throwable $e) {
            yield [
                'type' => 'meta',
                'data' => [
                    'siteHandle'    => $siteHandle,
                    'url'           => $url,
                    'itemsOnPage'   => 0,
                    'paginatorNode' => $link->paginatorNode,
                    'paginatorValue' => null,
                    'limit'         => $limit,
                    'matchAttribute' => $matchAttr,
                    'matchNode'     => $matchNode,
                    'offset'        => $offset,
                    'offsetLabel'   => $offsetLabel,
                    'offsetQuery'   => $queryParams,
                    'error'         => $e->getMessage(),
                ],
            ];
            return;
        }

        $paginatorValue = null;
        if ($link->paginatorNode) {
            $next = Hash::get($data, $link->paginatorNode);
            $paginatorValue = is_string($next) ? $next : null;
        }

        $list = $plugin->data->rootList($link, $data);

        yield [
            'type' => 'meta',
            'data' => [
                'siteHandle'     => $siteHandle,
                'url'            => $url,
                'itemsOnPage'    => count($list),
                'paginatorNode'  => $link->paginatorNode,
                'paginatorValue' => $paginatorValue,
                'limit'          => $limit,
                'matchAttribute' => $matchAttr,
                'matchNode'      => $matchNode,
                'offset'         => $offset,
                'offsetLabel'    => $offsetLabel,
                'offsetQuery'    => $queryParams,
                'error'          => null,
            ],
        ];

        $siteId = $siteHandle
            ? (Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id)
            : null;

        $index = 0;
        foreach (array_slice($list, 0, $limit) as $item) {
            $row = $this->debugItem($link, $target, $item, $siteId);
            $row['index'] = $index++;
            yield ['type' => 'item', 'data' => $row];
        }
    }

    /**
     * Public entry point: run the per-item inspection against an already-fetched
     * remote item. Used by the log detail drill-down to reuse the debug
     * machinery against a historical row's stored payload.
     */
    public function inspectItem(Link $link, array $item, ?int $siteId = null): array
    {
        $target = Influx::getInstance()->targets->forLink($link);
        if (!$target) {
            return [
                'matchAttribute' => $link->matchAttribute(),
                'matchNode'      => null,
                'matchValue'     => null,
                'element'        => null,
                'isNew'          => false,
                'action'         => 'error',
                'message'        => null,
                'raw'            => $item,
                'mappings'       => [],
                'error'          => "No element target registered for '{$link->elementType}'.",
            ];
        }
        return $this->debugItem($link, $target, $item, $siteId);
    }

    private function debugItem(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?int $siteId,
    ): array {
        $matchAttr = $link->matchAttribute();
        $matchNode = $matchAttr ? ($link->mappings[$matchAttr]['node'] ?? null) : null;
        $matchValue = $link->matchValue($item);

        $row = [
            'matchAttribute' => $matchAttr,
            'matchNode'      => $matchNode,
            'matchValue'     => $matchValue,
            'element'        => null,
            'isNew'          => false,
            'action'         => 'would-skip',
            'message'        => null,
            'raw'            => $item,
            'mappings'       => [],
            'error'          => null,
        ];

        if ($matchValue === null || $matchValue === '') {
            $row['message'] = "Remote item has no value at match path '"
                . ($matchNode ?? '?') . "' (match attribute: "
                . ($matchAttr ?? '?') . ").";
            return $row;
        }

        try {
            $element = $target->findByMatchValue($link, $matchValue, $siteId);
        } catch (\Throwable $e) {
            $row['error'] = 'findByMatchValue: ' . $e->getMessage();
            return $row;
        }

        if ($element) {
            $row['element'] = $this->describeElement($element);
        }

        switch ($link->decideAction($matchValue, $element)) {
            case Link::DECISION_SKIP_NO_UPDATE:
                $row['action'] = 'would-skip';
                $row['message'] = "'update' not enabled for this link.";
                break;
            case Link::DECISION_SKIP_NO_CREATE:
                $row['action'] = 'would-skip';
                $row['message'] = "No existing element and 'create' not enabled.";
                break;
            case Link::DECISION_UPDATE:
                $row['action'] = 'would-update';
                break;
            case Link::DECISION_CREATE:
                $row['action'] = 'would-create';
                $row['isNew'] = true;
                try {
                    $element = $target->buildNew($link, $siteId);
                    $target->assignMatchValue($element, $link, $matchValue);
                } catch (\Throwable $e) {
                    $row['error'] = 'buildNew: ' . $e->getMessage();
                    return $row;
                }
                break;
        }

        if ($element === null) {
            return $row;
        }

        if ($siteId) {
            $element->siteId = $siteId;
        }

        $row['mappings'] = $this->debugMappings($link, $target, $element, $item, $row['isNew']);

        // Existing element with no per-field changes → downgrade to unchanged.
        if ($row['action'] === 'would-update') {
            $anyChange = false;
            foreach ($row['mappings'] as $m) {
                if (!empty($m['changed'])) {
                    $anyChange = true;
                    break;
                }
            }
            if (!$anyChange) {
                $row['action'] = 'would-unchanged';
            }
        }

        return $row;
    }

    /**
     * @return list<array>
     */
    private function debugMappings(Link $link, ElementTargetInterface $target, ElementInterface $element, array $item, bool $isNew): array
    {
        $plugin = Influx::getInstance();
        $fields = $plugin->fields;
        $layout = $element->getFieldLayout();

        $rows = [];

        foreach ($link->mappings as $handle => $config) {
            if (!is_array($config)) {
                continue;
            }

            $mappingRow = [
                'handle'       => $handle,
                'node'         => $config['node'] ?? null,
                'default'      => $config['default'] ?? null,
                'native'       => true,
                'rawValue'     => $this->describeValue($this->safeHashGet($item, $config['node'] ?? null)),
                'parsedValue'  => null,
                'currentValue' => null,
                'changed'      => null,
                'note'         => null,
                'error'        => null,
            ];

            if ($target->ownsAttribute($link, $handle)) {
                $mappingRow['note'] = 'Managed by target.';
                $rows[] = $mappingRow;
                continue;
            }

            $craftField = $layout?->getFieldByHandle($handle);

            if ($craftField === null) {
                // Native attribute — no strategy parse path, just surface the raw.
                try {
                    $mappingRow['currentValue'] = $this->describeValue($element->{$handle} ?? null);
                } catch (\Throwable) {
                    $mappingRow['currentValue'] = null;
                }
                $mappingRow['changed'] = $isNew ? true : null;
                $rows[] = $mappingRow;
                continue;
            }

            $mappingRow['native'] = false;

            try {
                $strategy = $fields->forCraftField($craftField);
                $strategy->setContext($craftField, $handle, $config, $item, $link, $element, dryRun: true);
                $value = $strategy->parseField();
                try {
                    $displayValue = $craftField->normalizeValue($value, $element);
                } catch (\Throwable) {
                    $displayValue = $value;
                }
                $mappingRow['parsedValue'] = $this->describeValue($displayValue);

                try {
                    $current = $element->getFieldValue($handle);
                    $mappingRow['currentValue'] = $this->describeValue($current);
                } catch (\Throwable) {
                    $mappingRow['currentValue'] = null;
                }

                if ($value === null) {
                    $mappingRow['changed'] = false;
                    $mappingRow['note'] = 'Strategy returned null — field left untouched.';
                } else {
                    $mappingRow['changed'] = $isNew ? true : $strategy->hasChanged($element, $value);
                }
            } catch (\Throwable $e) {
                $mappingRow['error'] = $e->getMessage();
            }

            $rows[] = $mappingRow;
        }

        return $rows;
    }

    private function safeHashGet(array $item, ?string $node): mixed
    {
        if ($node === null || $node === '') {
            return null;
        }
        try {
            return Hash::get($item, $node);
        } catch (\Throwable) {
            return null;
        }
    }

    private function describeElement(ElementInterface $element): array
    {
        return [
            'id'        => $element->id,
            'title'     => (string)($element->title ?? '#' . $element->id),
            'cpEditUrl' => $element->getCpEditUrl(),
            'siteId'    => $element->siteId,
        ];
    }

    /**
     * Make a value safe to render in Twig — scalar through, objects/arrays to
     * a compact string representation. Truncated so a giant CKEditor blob
     * doesn't blow up the page.
     */
    private function describeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            $str = (string)$value;
        } elseif ($value instanceof \DateTimeInterface) {
            $str = $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $ids = [];
            try {
                $ids = $value->ids();
            } catch (\Throwable) {
            }
            $str = '[' . implode(', ', array_map('strval', $ids)) . ']';
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $str = (string)$value;
        } else {
            $str = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (strlen($str) > 500) {
            $str = substr($str, 0, 500) . '…';
        }
        return $str;
    }
}
