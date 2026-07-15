<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\enums\SyncDecision;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\sync\ItemProcessor;
use GlueAgency\Influx\sync\ItemResolution;
use GlueAgency\Influx\sync\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\ElementTargetInterface;
use GlueAgency\Influx\web\ItemRowPresenter;
use Throwable;

/**
 * The shared per-item inspection engine: runs one remote item through the exact
 * {@see ItemProcessor} pipeline the real sync uses, but with `dryRun: true` so
 * nothing is written — no logs, no element saves, no cooldown marks — then
 * presents the resolved element + per-field mapping results as row arrays.
 *
 * Two consumers share this one engine so the logic exists exactly once: the
 * Links overview "Debug" view ({@see DebugService::streamSite()}, which fans a
 * whole first page through {@see inspectWithTarget()}) and the log detail
 * drill-down ({@see inspectItem()}, one historical stored payload).
 */
class InspectorService extends Component
{
    /**
     * The same pipeline {@see SynchronizationService} runs — invoked here
     * with dry-run contexts and never committed.
     */
    protected ItemProcessor $itemProcessor;

    /**
     * Shapes resolved elements + mapping results into the Twig/JS row arrays
     * the debug view renders. Kept separate from the orchestration here so the
     * presentation is unit-testable without booting a sync.
     */
    protected ItemRowPresenter $rows;

    public function init(): void
    {
        parent::init();
        $this->itemProcessor = new ItemProcessor();
        $this->rows = new ItemRowPresenter();
    }

    /**
     * Inspect an already-fetched remote item against a link, resolving the
     * link's element target first. Used by the log detail drill-down to reuse
     * the inspection machinery against a historical row's stored payload;
     * callers that already hold the target (the debug stream) skip the lookup
     * and call {@see inspectWithTarget()} directly.
     *
     * $pinnedElementId, when given, resolves straight to that element instead
     * of re-deriving one from the match value — see {@see inspectWithTarget()}.
     *
     * $withParsedHtml is threaded down to the presenter so the log drill-down
     * can render rich parsed values server-side (element chips for relations,
     * a lightswitch for booleans); it defaults to false, leaving the streaming
     * debug path untouched.
     */
    public function inspectItem(
        Link $link,
        array $item,
        ?string $siteHandle = null,
        ?int $pinnedElementId = null,
        bool $withParsedHtml = false,
    ): array {
        $target = Influx::getInstance()->targets->forLink($link);

        if (! $target) {
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

        return $this->inspectWithTarget($link, $target, $item, $siteHandle, $pinnedElementId, $withParsedHtml);
    }

    /**
     * One item through the shared {@see ItemProcessor} pipeline with
     * `dryRun: true` — resolve and populate run for real (in memory),
     * commit is never called. This method only presents the result; the
     * logic is the exact code the sync run executes. The target is passed in
     * pre-resolved so the debug stream resolves it once for a whole page.
     *
     * $pinnedElementId short-circuits the match-value lookup and resolves
     * straight to that element. Without it, `resolve()` re-derives the element
     * from the match value scoped to `$siteHandle` — for the log drill-down
     * that's a problem specifically for element-triggered runs: the log only
     * carries the run's site (null there, since one run can span several sites),
     * so an unscoped match-value lookup is ambiguous whenever more than one
     * element shares that match value across sites (e.g. a non-propagated
     * section where each site has its own row with the same import id). The log
     * item DOES know which element the run actually touched, so the drill-down
     * pins to it directly instead of re-guessing.
     *
     * $withParsedHtml is passed straight through to the presenter's mapping
     * rendering (both call sites below) so a rich parsed value can render
     * server-side (chips, lightswitch); false on the streaming debug path.
     */
    public function inspectWithTarget(
        Link $link,
        ElementTargetInterface $target,
        array $item,
        ?string $siteHandle,
        ?int $pinnedElementId = null,
        bool $withParsedHtml = false,
    ): array {
        $context = SyncContext::forSite($link, $target, $siteHandle, dryRun: true);
        $remoteItem = new RemoteItem($item);

        $matchAttr = $link->matchAttribute();
        $row = [
            'matchAttribute' => $matchAttr,
            'matchNode'      => $matchAttr ? ($link->getMappingCollection()->get($matchAttr)?->node) : null,
            'matchValue'     => null,
            'element'        => null,
            'isNew'          => false,
            'action'         => 'would-skip',
            'message'        => null,
            'raw'            => $item,
            'mappings'       => [],
            'error'          => null,
        ];

        try {
            $resolution = $pinnedElementId !== null
                ? $this->resolvePinned($link, $target, $remoteItem, $pinnedElementId, $context->siteId)
                : $this->itemProcessor->resolve($context, $remoteItem);
        } catch (Throwable $e) {
            $row['error'] = 'findByMatchValue: ' . $e->getMessage();

            return $row;
        }

        $row['matchValue'] = $resolution->matchValue;

        if ($resolution->element) {
            $row['element'] = $this->rows->presentElement($resolution->element);
        }

        try {
            $result = $this->itemProcessor->populate($context, $remoteItem, $resolution);
        } catch (Throwable $e) {
            // populate() only throws from buildNew(); mapping errors are captured per-row
            $row['isNew'] = $resolution->decision === SyncDecision::CREATE;
            $row['action'] = $row['isNew'] ? ItemAction::CREATED->dryRunLabel() : ItemAction::UPDATED->dryRunLabel();
            $row['error'] = 'buildNew: ' . $e->getMessage();

            return $row;
        }

        $row['isNew'] = $result->isNew;

        if ($result->decision->isSkip()) {
            $row['action'] = ItemAction::SKIPPED->dryRunLabel();
            $row['message'] = $result->message;

            // Skipped-but-existing: preview a forced Update so the user sees what 'update' would do
            if ($result->decision === SyncDecision::SKIP_NO_UPDATE && $resolution->element !== null) {
                try {
                    $preview = $this->itemProcessor->populate(
                        $context,
                        $remoteItem,
                        new ItemResolution($resolution->matchValue, $resolution->element, SyncDecision::UPDATE),
                    );
                    $row['mappings'] = $this->rows->presentMappingResults($preview->mappingResults, $resolution->element, $this->rows->fieldLabels($link, $target), $withParsedHtml);
                } catch (Throwable $e) {
                    $row['error'] = $e->getMessage();
                }
            }

            return $row;
        }

        $row['action'] = $result->action->dryRunLabel();

        if ($result->element !== null) {
            $row['mappings'] = $this->rows->presentMappingResults($result->mappingResults, $result->element, $this->rows->fieldLabels($link, $target), $withParsedHtml);
        }

        return $row;
    }

    /**
     * Resolve straight to a known element instead of re-deriving one from
     * the match value — see {@see inspectWithTarget()} for why.
     *
     * Loads the element in the run's own site (`$siteId`) when it had one, so
     * the presented chip's edit link points at the site row the run actually
     * touched — not whichever site `'*'` happens to sort first (which surfaces
     * a foreign-language element for a site-scoped run). `$siteId` is null for
     * runs with no single site (element-triggered / all-sites); those fall back
     * to `'*'`. The site-specific load also falls back to `'*'` if the element
     * isn't propagated to that site, so the drill-down still shows it.
     */
    protected function resolvePinned(Link $link, ElementTargetInterface $target, RemoteItem $item, int $elementId, ?int $siteId = null): ItemResolution
    {
        $matchValue = $link->matchValue($item);
        $elements = Craft::$app->getElements();
        $element = $elements->getElementById($elementId, $target::elementType(), $siteId ?? '*');

        if ($element === null && $siteId !== null) {
            $element = $elements->getElementById($elementId, $target::elementType(), '*');
        }

        return new ItemResolution($matchValue, $element, SyncDecision::decide($link, $matchValue, $element));
    }
}
