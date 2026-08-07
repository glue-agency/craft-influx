<?php

namespace GlueAgency\Influx\targets;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;
use craft\models\FieldLayout;
use craft\models\Volume;
use GlueAgency\Influx\exceptions\AssetUploadException;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\schema\NativeAttributes;
use GlueAgency\Influx\schema\SchemaBuilder;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;

/**
 * Target for craft\elements\Asset.
 *
 * Recognized elementCriteria key — {@see CRITERIA_VOLUME}, the handle of the
 * volume (required for new assets: it names both the folder the file lands in and
 * the field layout the mappings write to). This target OWNS that key name.
 *
 * An asset is the one element type that can't exist without a FILE, which is what
 * shapes this target. Nothing about that is special-cased: the file arrives
 * through a mapping like any other value — the native `url` row, whose
 * {@see parseUrl()} downloads it — WHETHER an asset gets created is the link's
 * `processing` policy, and what an item is paired with is the link's match key. So
 * a link that maps no `url` can still update titles, alt text and custom fields on
 * assets that already exist; one that maps it can seed a library.
 *
 * Downloading goes through {@see AssetUploadService}, which owns the HTTP client
 * and — crucially, since the URL is feed-controlled — the http(s)-only scheme
 * guard. This target takes the temp file and hands it to the element, leaving the
 * one write to the engine's own {@see save()} rather than committing a second
 * asset behind its back (which is what {@see AssetUploadService::uploadFromUrl()}
 * does for the relational Assets FIELD, where the caller wants a saved asset to
 * relate to).
 *
 * A volume IS the ownership boundary, so this target sweeps.
 */
class AssetTarget extends AbstractElementTarget
{
    public const CRITERIA_VOLUME = 'volume';

    /**
     * The mapping handle carrying the remote file's URL. A handle rather than a
     * bare literal because three members have to agree on it: the descriptor
     * {@see nativeFieldDefinitions()} declares, the `parse{Handle}()` dispatch that
     * writes it, and {@see save()}'s error, which lands on that row.
     */
    protected const HANDLE_URL = 'url';

    public static function elementType(): string
    {
        return Asset::class;
    }

    /**
     * Assets are scoped by volume — the one dropdown the builder's General tab
     * renders for this element type.
     *
     * @return list<string>
     */
    public static function criteriaKeys(): array
    {
        return [self::CRITERIA_VOLUME];
    }

    /** @return list<array> */
    public static function criteriaSchema(): array
    {
        $options = [self::criteriaPlaceholder()];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['value' => $volume->handle, 'label' => $volume->name];
        }

        return SchemaBuilder::make()
            ->select([
                'handle'  => self::CRITERIA_VOLUME,
                'label'   => Craft::t('app', 'Volume'),
                'options' => $options,
            ])
            ->toArray();
    }

    public function criteriaLabel(Link $link): ?string
    {
        $handle = $link->criterion(self::CRITERIA_VOLUME);

        if (! $handle) {
            return null;
        }

        return $this->volumeByHandle($handle)?->name ?? $handle;
    }

    /**
     * The volume is compared by ID rather than through {@see Asset::getVolume()},
     * which answers with Craft's TEMPORARY volume for an asset that has none — an
     * upload mid-flight would then be measured against a volume nobody configured.
     * See {@see CategoryTarget::targetsElement()} for the other half of the reason.
     */
    public function targetsElement(Link $link, ElementInterface $element): bool
    {
        if (! ($element instanceof Asset)) {
            return false;
        }

        if (! $this->handles($link)) {
            return false;
        }

        $volumeHandle = $link->criterion(self::CRITERIA_VOLUME);

        return $volumeHandle === null || $this->volumeByHandle($volumeHandle)?->id === $element->getVolumeId();
    }

    /**
     * Assets partition by volume — the volume the link names, or every volume
     * there is when it names none.
     *
     * @return list<string>
     */
    public function claimCells(Link $link): array
    {
        $volume = $link->criterion(self::CRITERIA_VOLUME);

        if ($volume !== null) {
            return [$volume];
        }

        $cells = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $candidate) {
            $cells[] = $candidate->handle;
        }

        return $cells;
    }

    public function findByMatchValue(Link $link, mixed $matchValue, ?int $siteId = null): ?Asset
    {
        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $matchValue === null || $matchValue === '') {
            return null;
        }

        $query = Asset::find()
            ->status(null)
            ->{$matchAttr}($matchValue);

        return $this->scopeToLink($query, $link, $siteId)->one();
    }

    /**
     * Candidate set for the missing-elements sweep: every asset this link owns,
     * minus the ids the run just saw. See
     * {@see EntryTarget::missingElementsQuery()} for why a blank match value is a
     * candidate rather than an exclusion.
     *
     * A `delete` policy here deletes the FILE along with the element, which is
     * Craft's own semantics for deleting an asset — worth knowing before pointing
     * one at a volume.
     */
    public function missingElementsQuery(Link $link, array $seenIds, ?int $siteId): ?ElementQueryInterface
    {
        if (! $link->matchAttribute()) {
            return null;
        }

        $query = $this->scopeToLink(Asset::find(), $link, $siteId);

        if ($seenIds !== []) {
            $query->id(array_merge(['not'], $seenIds));
        }

        return $query;
    }

    /**
     * A fresh asset in the root folder of the link's volume, under the CREATE
     * scenario — which is what makes a create with no file FAIL rather than land a
     * fileless row (see {@see save()} for the message that failure carries).
     *
     * The root folder rather than a configurable subpath: the volume is the whole
     * of this target's scope, and a per-item destination would be a second
     * ownership boundary the sweep couldn't see.
     */
    public function buildNew(Link $link, ?int $siteId = null): Asset
    {
        $volume = $this->requireVolume($link);
        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume('', $volume);

        $asset = new Asset();
        $asset->setVolumeId($volume->id);
        $asset->newFolderId = $folder->id;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if ($siteId) {
            $asset->siteId = $siteId;
        }

        return $asset;
    }

    /**
     * The asset identifiers, from the one list this element type publishes
     * ({@see NativeAttributes::assetMatchable()}), gated on the volume's layout —
     * a volume can drop the Title field, and matching on a title nobody can edit
     * would be matching on whatever Craft generated from the filename.
     */
    public function matchableNativeAttributes(Link $link): array
    {
        return NativeAttributes::assetMatchable($this->fieldLayout($link));
    }

    public function getMappableFields(Link $link): array
    {
        $layout = $this->fieldLayout($link);

        return array_merge(
            $this->nativeFieldDefinitions($layout)->toArray(),
            $this->customFieldDescriptors($layout, Craft::t('influx', 'Content')),
        );
    }

    public function fieldLayout(Link $link): ?FieldLayout
    {
        return $this->volume($link)?->getFieldLayout();
    }

    /**
     * Craft's save, refusing a fileless create with a message on the row that
     * would have carried the file.
     *
     * Without this the same failure still happens — {@see Asset::SCENARIO_CREATE}
     * requires `tempFilePath` — but as Craft's own "Temp file path cannot be
     * blank", keyed to an attribute no mapping row is named after, so it lands in
     * the item's message blob instead of on the `url` row an operator would go fix
     * ({@see \GlueAgency\Influx\sync\item\ValidationErrorRouter}).
     */
    public function save(ElementInterface $element): bool
    {
        if (! $element->id && ! ($element instanceof Asset && $element->tempFilePath)) {
            $element->addError(self::HANDLE_URL, Craft::t(
                'influx',
                'An asset can’t be created without a file — map the File URL, or turn off “Create” for this link.',
            ));

            return false;
        }

        return parent::save($element);
    }

    /**
     * THE definition of "which assets this link owns" — its volume (only when set)
     * plus the site scope — shared by {@see findByMatchValue()} and
     * {@see missingElementsQuery()} so the two can't drift apart.
     */
    protected function scopeToLink(AssetQuery $query, Link $link, ?int $siteId): AssetQuery
    {
        if (($volume = $link->criterion(self::CRITERIA_VOLUME)) !== null) {
            $query->volume($volume);
        }

        if ($siteId) {
            $query->siteId($siteId);
        } else {
            $query->siteId('*')->unique();
        }

        return $query;
    }

    /**
     * Fetch the remote file and hand it to the element for the engine's save to
     * commit. Reports true only when a file was actually attached, so an unchanged
     * asset still skips its save.
     *
     * Deliberately NOT symmetrical with the other natives: an empty value is a
     * no-op rather than a clear, because there is no such thing as an asset with
     * its file removed. The feed is authoritative about an asset's metadata, not
     * about whether it is still an asset.
     *
     * Re-fetching is the `conflict` option's business. Under `index` (the default)
     * an existing asset whose filename already matches the URL is left alone — the
     * fetch is skipped entirely, so a nightly re-sync doesn't re-download an
     * unchanged library. `replace` fetches every time and overwrites the file in
     * place, keeping the element (and every relation to it) intact.
     *
     * Nothing is downloaded under dry-run: an inspector run must not touch the
     * network or the filesystem, the same line {@see \GlueAgency\Influx\fields\Assets::resolveByUrl()}
     * draws.
     *
     * @throws AssetUploadException when the download fails — surfaced as an error
     * row rather than swallowed, since "no file" and "the fetch broke" are
     * different outcomes.
     */
    protected function parseUrl(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Asset $element */
        $url = $mapping->resolve($item);

        if ($url === null || $url === '') {
            return false;
        }

        $uploads = $this->uploads();
        $filename = $uploads->filenameFor((string) $url);

        if ($element->id && $mapping->option('conflict', 'index') !== 'replace' && $element->getFilename() === $filename) {
            return false;
        }

        if ($context->dryRun) {
            return false;
        }

        $element->tempFilePath = $uploads->downloadToTemp((string) $url);
        $element->setScenario($element->id ? Asset::SCENARIO_REPLACE : Asset::SCENARIO_CREATE);

        // A new asset has no name yet; an existing one keeps the one it has, so its
        // URL stays stable while the bytes behind it change. A mapped `filename`
        // row still wins either way — it lands as `newFilename`, which Craft
        // resolves after this.
        if (! $element->id && $element->newFilename === null) {
            $element->setFilename($filename);
        }

        return true;
    }

    /**
     * Rename the file. Assigned as `newFilename` rather than straight onto
     * `filename`, which is what makes Craft actually move the file on disk instead
     * of leaving the row pointing at a name that isn't there; Craft itself drops
     * the rename when the name is unchanged, so a re-sync is a no-op.
     *
     * An empty value is a no-op for the same reason {@see parseUrl()}'s is: an
     * asset with no filename isn't a thing.
     */
    protected function parseFilename(SyncContext $context, ElementInterface $element, RemoteItem $item, FieldMapping $mapping): bool
    {
        /** @var Asset $element */
        $value = $mapping->resolve($item);

        if ($value === null || $value === '') {
            return false;
        }

        $filename = $this->uploads()->filenameFor((string) $value);

        if ($element->id && $element->getFilename() === $filename) {
            return false;
        }

        $element->newFilename = $filename;

        return true;
    }

    /**
     * The Asset-native mappable attributes: the file's own URL, then the writables
     * the volume's layout exposes ({@see NativeAttributes::assetWritable()}) and
     * the `enabled` flag every element type carries.
     *
     * The URL row is a plain text one — its value is a remote address, so there's
     * nothing to pick in the CP and no element selector to offer — carrying the
     * same `conflict` vocabulary the relational Assets field uses for the same
     * decision.
     */
    protected function nativeFieldDefinitions(?FieldLayout $layout = null): MappingSchemaBuilder
    {
        return MappingSchemaBuilder::make()
            ->group(Craft::t('influx', 'Native'), function(MappingSchemaBuilder $group) use ($layout): void {
                $group->text([
                    'handle' => self::HANDLE_URL,
                    'name'   => Craft::t('influx', 'File URL'),
                    'extras' => fn(MappingSchemaBuilder $builder) => $builder->select([
                        'handle'  => 'conflict',
                        'label'   => Craft::t('influx', 'On re-sync'),
                        'options' => [
                            ['value' => 'index',   'label' => Craft::t('influx', 'Keep the existing file')],
                            ['value' => 'replace', 'label' => Craft::t('influx', 'Re-download and replace')],
                        ],
                        'default' => 'index',
                    ]),
                ]);

                foreach (NativeAttributes::assetWritable($layout) as $attribute) {
                    $group->text([
                        'handle' => $attribute['handle'],
                        'name'   => $attribute['label'],
                    ]);
                }

                $group->select([
                    'handle'  => 'enabled',
                    'name'    => Craft::t('app', 'Enabled'),
                    'options' => [
                        'true'  => Craft::t('app', 'Enabled'),
                        'false' => Craft::t('app', 'Disabled'),
                    ],
                ]);
            });
    }

    /**
     * Lenient volume resolution for UI/read paths — an unset or unknown handle
     * yields null, so a half-configured link still reports its natives.
     */
    protected function volume(Link $link): ?Volume
    {
        $handle = $link->criterion(self::CRITERIA_VOLUME);

        return $handle ? $this->volumeByHandle($handle) : null;
    }

    /**
     * Strict volume resolution for the write path, naming the offending handle.
     *
     * @throws InfluxException when the volume criteria is missing or unknown.
     */
    protected function requireVolume(Link $link): Volume
    {
        $handle = $link->criterion(self::CRITERIA_VOLUME);

        if (! $handle) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.volume for Asset targets.",
            );
        }

        $volume = $this->volumeByHandle($handle);

        if (! $volume) {
            throw new InfluxException("Volume '{$handle}' does not exist.");
        }

        return $volume;
    }

    /**
     * The one Craft lookup, isolated as a seam so the resolution above is testable
     * without a booted Craft.
     */
    protected function volumeByHandle(string $handle): ?Volume
    {
        return Craft::$app->getVolumes()->getVolumeByHandle($handle);
    }

    /**
     * The download service, as a seam — so a spec can exercise the fetch/skip
     * decision without HTTP.
     */
    protected function uploads(): AssetUploadService
    {
        return Influx::getInstance()->assetUpload;
    }
}
