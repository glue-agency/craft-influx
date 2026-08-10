<?php

namespace GlueAgency\Influx;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\CreateFieldLayoutFormEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\services\Gc;
use craft\services\ProjectConfig as ProjectConfigService;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use GlueAgency\Influx\integrations\craftcms\feedme\services\FeedMeService;
use GlueAgency\Influx\models\Settings;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\services\AuthService;
use GlueAgency\Influx\services\BackupService;
use GlueAgency\Influx\services\CooldownService;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\services\EndpointTokensService;
use GlueAgency\Influx\services\FieldsService;
use GlueAgency\Influx\services\InspectorService;
use GlueAgency\Influx\services\LinkBuilderService;
use GlueAgency\Influx\services\LinksService;
use GlueAgency\Influx\services\LogsService;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\services\TargetsService;
use GlueAgency\Influx\web\assets\editor\FieldIndicatorAsset;
use GlueAgency\Influx\web\SyncButtonPresenter;
use GlueAgency\Influx\web\twig\InfluxTwigExtension;
use yii\base\Event;

/**
 * Influx plugin.
 *
 * @method static Influx getInstance()
 * @method Settings getSettings()
 * @property LinkBuilderService $linkBuilder
 * @property LinksService $links
 * @property DataService $data
 * @property SynchronizationService $synchronization
 * @property FieldsService $fields
 * @property LogsService $logs
 * @property TargetsService $targets
 * @property CooldownService $cooldown
 * @property BackupService $backup
 * @property AssetUploadService $assetUpload
 * @property DebugService $debug
 * @property InspectorService $inspector
 * @property AuthService $auth
 * @property EndpointTokensService $endpointTokens
 * @property FeedMeService $feedMe
 */
class Influx extends Plugin
{
    public string $schemaVersion = '1.4.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = true;

    /**
     * Permission gating sync triggers — the entry "Sync from remote" button
     * and the SynchronizationController endpoints. Deliberately NOT nested
     * under the CP-section permission: an entry editor can hold it without
     * Influx CP access. Admins always pass.
     */
    public const PERMISSION_SYNC = 'influx:sync';

    /**
     * Mapped field handles collected so far this request, for
     * {@see registerFieldIndicators()}.
     *
     * Request-scoped state on the plugin because the thing it accumulates across
     * is a request: {@see FieldLayout::EVENT_CREATE_FORM} fires once per rendered
     * layout, and a page with nested layouts (a Matrix block's entry type) fires it
     * several times for different elements. The JS var takes one flat handle list,
     * so each firing has to widen the same set rather than replace it.
     *
     * @var list<string>
     */
    protected array $fieldIndicatorHandles = [];

    public static function config(): array
    {
        return [
            'components' => [
                'linkBuilder'     => LinkBuilderService::class,
                'links'           => LinksService::class,
                'data'            => DataService::class,
                'synchronization' => SynchronizationService::class,
                'fields'          => FieldsService::class,
                'logs'            => LogsService::class,
                'targets'         => TargetsService::class,
                'cooldown'        => CooldownService::class,
                'backup'          => BackupService::class,
                'assetUpload'     => AssetUploadService::class,
                'debug'           => DebugService::class,
                'inspector'       => InspectorService::class,
                'auth'            => AuthService::class,
                'endpointTokens'  => EndpointTokensService::class,
                'feedMe'          => FeedMeService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@influx', __DIR__);

        $this->registerProjectConfigEventListeners();

        Craft::$app->onInit(function() {
            $this->registerControllers();
            $this->registerCpRoutes();
            $this->registerCpTemplateRoots();
            $this->registerTwigExtensions();
            $this->registerSyncButton();
            $this->registerFieldIndicators();
            $this->registerGarbageCollection();
            $this->registerPermissions();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    /**
     * Send the Settings → Plugins entry to the plugin's own settings screen —
     * the same one the CP nav dropdown's "Settings" item opens — so both routes
     * land on one page (mirrors SeoMatic). Overriding this instead of rendering
     * settingsHtml() keeps the full custom settings UI.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('influx/settings'));
    }

    /**
     * Craft removes `plugins.influx` on uninstall, but the links live under
     * the plugin's OWN root `influx` key — drop it too, so an uninstall
     * leaves no orphaned config in project.yaml.
     */
    protected function beforeUninstall(): void
    {
        Craft::$app->getProjectConfig()->remove('influx');
    }

    /**
     * The error badge runs on every CP request, so its count is cached and
     * change-invalidated by {@see LogsService::errorLogCount()} rather than
     * re-queried per nav render.
     */
    public function getCpNavItem(): ?array
    {
        $parent = parent::getCpNavItem();

        $parent['url'] = 'influx';
        $parent['label'] = Craft::t('influx', 'Influx');
        $parent['subnav'] = [
            'links' => [
                'label' => Craft::t('influx', 'Links'),
                'url'   => 'influx/links',
            ],
            'logs' => [
                'label' => Craft::t('influx', 'Logs'),
                'url'   => 'influx/logs',
            ],
        ];

        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $parent['subnav']['settings'] = [
                'label' => Craft::t('influx', 'Settings'),
                'url'   => 'influx/settings',
            ];
        }

        $errorCount = $this->logs->errorLogCount();

        if ($errorCount > 0) {
            $parent['badgeCount'] = $errorCount;
            $parent['subnav']['logs']['badgeCount'] = $errorCount;
        }

        return $parent;
    }

    protected function registerControllers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'GlueAgency\\Influx\\console\\controllers';

            return;
        }

        $this->controllerNamespace = 'GlueAgency\\Influx\\controllers';
    }

    /**
     * A few rules aren't one screen per route: viewing an existing link reuses
     * the builder with its fields disabled rather than a separate detail view;
     * the inspector is one standalone screen scoped by `?link=<handle>` instead
     * of a page per link; and the `influx/link-builder/*` rules are the JSON
     * routes the LinkBuilder SPA talks to
     * ({@see \GlueAgency\Influx\controllers\LinkBuilderController}).
     */
    protected function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['influx'] = 'influx/links/index';
                $event->rules['influx/links'] = 'influx/links/index';
                $event->rules['influx/links/new'] = 'influx/links/edit';
                $event->rules['influx/links/<id:\d+>/duplicate'] = 'influx/links/duplicate';
                $event->rules['influx/links/<id:\d+>'] = 'influx/links/edit';
                $event->rules['influx/links/<id:\d+>/edit'] = 'influx/links/edit';

                $event->rules['influx/debug'] = 'influx/links/debug';
                $event->rules['influx/debug/inspect'] = 'influx/links/debug-inspect';

                $event->rules['influx/link-builder/bootstrap'] = 'influx/link-builder/bootstrap';
                $event->rules['influx/link-builder/save'] = 'influx/link-builder/save';
                $event->rules['influx/link-builder/fetch-sample'] = 'influx/link-builder/fetch-sample';
                $event->rules['influx/link-builder/mappable-fields'] = 'influx/link-builder/mappable-fields';
                $event->rules['influx/link-builder/endpoint-token-suggestions'] = 'influx/link-builder/endpoint-token-suggestions';
                $event->rules['influx/link-builder/render-element-select'] = 'influx/link-builder/render-element-select';
                $event->rules['influx/link-builder/default-options'] = 'influx/link-builder/default-options';
                $event->rules['influx/link-builder/render-icon-picker'] = 'influx/link-builder/render-icon-picker';

                $event->rules['influx/logs'] = 'influx/logs/index';
                $event->rules['influx/logs/<id:\d+>'] = 'influx/logs/view';
                $event->rules['influx/logs/<id:\d+>/items'] = 'influx/logs/items';
                $event->rules['influx/logs/items/<id:\d+>'] = 'influx/logs/item';

                $event->rules['influx/settings'] = 'influx/settings/edit';
            },
        );
    }

    protected function registerCpTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['influx'] = __DIR__ . '/templates';
            },
        );
    }

    protected function registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new InfluxTwigExtension());
    }

    /**
     * Wire LinksService into Craft's Project Config lifecycle so that:
     *   - new/changed links applied from YAML (e.g. via `project-config/apply`)
     *     invalidate the in-memory cache
     *   - removed links do the same
     *   - rebuilds (`project-config/rebuild`) emit the current state to YAML
     */
    protected function registerProjectConfigEventListeners(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleChangedLink'])
            ->onUpdate(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleChangedLink'])
            ->onRemove(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleDeletedLink']);

        Event::on(
            ProjectConfigService::class,
            ProjectConfigService::EVENT_REBUILD,
            function(RebuildConfigEvent $event) {
                $links = [];

                foreach ($this->links->getAllLinks() as $link) {
                    if ($link->uid) {
                        $links[$link->uid] = $link->getConfig();
                    }
                }
                $event->config['influx']['links'] = $links;
            },
        );
    }

    /**
     * Prune log rows older than the configured retention window (default 14
     * days) on Craft's periodic garbage-collection cycle. A non-positive value
     * — only reachable via an explicit config override — skips pruning.
     */
    protected function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $days = (int) $this->getSettings()->logRetentionDays;

            if ($days > 0) {
                $this->logs->deleteOlderThan($days);
            }
        });
    }

    /**
     * Register the plugin's user permissions. Currently just the sync-trigger
     * permission ({@see PERMISSION_SYNC}); a top-level entry so it can be
     * granted to entry editors independently of Influx CP-section access.
     */
    protected function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading'     => Craft::t('influx', 'Influx'),
                    'permissions' => [
                        self::PERMISSION_SYNC => [
                            'label' => Craft::t('influx', 'Sync elements from a remote link'),
                        ],
                    ],
                ];
            },
        );
    }

    /**
     * Flag every field an Influx mapping writes, on the edit screen of any
     * element the plugin targets: a small icon is injected next to each mapped
     * field's label, its tooltip explaining the value is set by synchronisation
     * — so an editor sees at a glance which values are Influx-managed and may be
     * overwritten on the next sync.
     *
     * Hooked on {@see FieldLayout::EVENT_CREATE_FORM} — the one thing EVERY edit
     * screen does, whoever wrote it. Craft's element editor calls
     * `createForm()`, and so do the screens that bypass it entirely: Global Sets
     * render `globals/_edit.twig`, and a third-party element type like Solspace
     * Calendar's Event renders its own template off its own route. None of those
     * reach {@see Element::EVENT_DEFINE_ADDITIONAL_BUTTONS}, which this used to
     * listen on, so the indicator simply never appeared there.
     *
     * The layout event is what the CP template hooks those screens offer can't be:
     * universal. It needs no per-element-type declaration, can't be withheld by a
     * plugin author who forgot a `{% hook %}`, and carries the element — which a
     * layout-level template hook does not.
     *
     * THREE GUARDS, because `createForm()` is called in more places than an edit
     * screen: a static render is read-only and gets no marks, a non-CP request has
     * no CP asset bundle to speak of, and the field-layout DESIGNER calls it with
     * no element at all.
     *
     * ACCUMULATES. Nested layouts fire this too — a Matrix block's entry type has
     * its own — so handles are unioned across the request rather than overwritten,
     * which a second {@see View::registerJsVar()} call would do. Re-registering
     * only when the union actually grew keeps the common single-layout page to one
     * emitted statement.
     *
     * Placement stays client-side: Craft renders its own field indicators inside
     * {@see \craft\helpers\Cp::fieldHtml()}, which fires no event and never
     * populates its `labelExtra` slot from a field layout, so there is no
     * server-side seam to write into. The mapped-handle set goes to a small
     * vanilla-JS asset ({@see FieldIndicatorAsset}) that decorates by handle and
     * mimics Craft's `<craft-tooltip>` treatment. No permission gate — the
     * indicator is purely informational.
     */
    protected function registerFieldIndicators(): void
    {
        Event::on(FieldLayout::class, FieldLayout::EVENT_CREATE_FORM, function(CreateFieldLayoutFormEvent $event) {
            if ($event->static || ! Craft::$app->getRequest()->getIsCpRequest()) {
                return;
            }

            $element = $event->element;

            if (! $element || ! $element->id) {
                return;
            }

            $handles = $this->links->mappedHandlesForElement($element);

            if ($handles === []) {
                return;
            }

            $union = array_values(array_unique(array_merge($this->fieldIndicatorHandles, $handles)));

            if (count($union) === count($this->fieldIndicatorHandles)) {
                return;
            }

            $this->fieldIndicatorHandles = $union;

            $view = Craft::$app->getView();
            $view->registerAssetBundle(FieldIndicatorAsset::class);
            $view->registerJsVar('influxFieldIndicators', $union);
        });
    }

    /**
     * Add a "Sync from remote" affordance to the edit page of any element the
     * plugin targets. Users without {@see PERMISSION_SYNC} get no button at all;
     * everything about WHAT gets offered and why — the resource-endpoint
     * requirement, the disabled states, the posted params — belongs to
     * {@see SyncButtonPresenter}, so this is the event wiring plus the
     * permission gate.
     *
     * Bound on the base {@see Element} class, not on a concrete one: Yii fires
     * class-level handlers for subclasses, so one registration covers every
     * registered target's element type — the same reason
     * {@see registerFieldIndicators()} binds there. It used to be `Entry::class`,
     * which is why the button never appeared for a user (or would have appeared
     * for none of the targets added since).
     */
    protected function registerSyncButton(): void
    {
        Event::on(Element::class, Element::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event) {
            if (! Craft::$app->getUser()->checkPermission(self::PERMISSION_SYNC)) {
                return;
            }

            /** @var ElementInterface $element */
            $element = $event->sender;

            $event->html .= (new SyncButtonPresenter())->html($element) ?? '';
        });
    }
}
